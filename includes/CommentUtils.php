<?php

namespace MediaWiki\Extension\DiscussionTools;

use LogicException;
use MediaWiki\MediaWikiServices;
use Title;
use Wikimedia\Assert\Assert;
use Wikimedia\Parsoid\DOM\Comment;
use Wikimedia\Parsoid\DOM\Element;
use Wikimedia\Parsoid\DOM\Node;
use Wikimedia\Parsoid\Utils\DOMCompat;

class CommentUtils {
	private function __construct() {
	}

	private static $blockElementTypes = [
		'div', 'p',
		// Tables
		'table', 'tbody', 'thead', 'tfoot', 'caption', 'th', 'tr', 'td',
		// Lists
		'ul', 'ol', 'li', 'dl', 'dt', 'dd',
		// HTML5 heading content
		'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hgroup',
		// HTML5 sectioning content
		'article', 'aside', 'body', 'nav', 'section', 'footer', 'header', 'figure',
		'figcaption', 'fieldset', 'details', 'blockquote',
		// Other
		'hr', 'button', 'canvas', 'center', 'col', 'colgroup', 'embed',
		'map', 'object', 'pre', 'progress', 'video'
	];

	/**
	 * @param Node $node
	 * @return bool Node is a block element
	 */
	public static function isBlockElement( Node $node ): bool {
		return $node instanceof Element &&
			in_array( strtolower( $node->tagName ), self::$blockElementTypes );
	}

	private const SOL_TRANSPARENT_LINK_REGEX =
		'/(?:^|\s)mw:PageProp\/(?:Category|redirect|Language)(?=$|\s)/D';

	/**
	 * @param Node $node
	 * @return bool Node is considered a rendering-transparent node in Parsoid
	 */
	public static function isRenderingTransparentNode( Node $node ): bool {
		$nextSibling = $node->nextSibling;
		return (
			$node instanceof Comment ||
			$node instanceof Element && (
				strtolower( $node->tagName ) === 'meta' ||
				(
					strtolower( $node->tagName ) === 'link' &&
					preg_match( self::SOL_TRANSPARENT_LINK_REGEX, $node->getAttribute( 'rel' ) ?? '' )
				) ||
				// Empty inline templates, e.g. tracking templates. (T269036)
				// But not empty nodes that are just the start of a non-empty template about-group. (T290940)
				(
					strtolower( $node->tagName ) === 'span' &&
					in_array( 'mw:Transclusion', explode( ' ', $node->getAttribute( 'typeof' ) ?? '' ) ) &&
					!self::htmlTrim( DOMCompat::getInnerHTML( $node ) ) &&
					(
						!$nextSibling || !( $nextSibling instanceof Element ) ||
						// Maybe we should be checking all of the about-grouped nodes to see if they're empty,
						// but that's prooobably not needed in practice, and it leads to a quadratic worst case.
						$nextSibling->getAttribute( 'about' ) !== $node->getAttribute( 'about' )
					)
				)
			)
		);
	}

	/**
	 * @param Node $node
	 * @return bool Node was added to the page by DiscussionTools
	 */
	public static function isOurGeneratedNode( Node $node ): bool {
		return $node instanceof Element && (
			DOMCompat::getClassList( $node )->contains( 'ext-discussiontools-init-replylink-buttons' ) ||
			$node->hasAttribute( 'data-mw-comment' ) ||
			$node->hasAttribute( 'data-mw-comment-start' ) ||
			$node->hasAttribute( 'data-mw-comment-end' )
		);
	}

	/**
	 * Elements which can't have element children (but some may have text content).
	 * https://html.spec.whatwg.org/#elements-2
	 * @var string[]
	 */
	private static $noElementChildrenElementTypes = [
		// Void elements
		'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
		'link', 'meta', 'param', 'source', 'track', 'wbr',
		// Raw text elements
		'script', 'style',
		// Escapable raw text elements
		'textarea', 'title',
		// Treated like text when scripting is enabled in the parser
		// https://html.spec.whatwg.org/#the-noscript-element
		'noscript',
	];

	/**
	 * @param Node $node
	 * @return bool If true, node can't have element children. If false, it's complicated.
	 */
	public static function cantHaveElementChildren( Node $node ): bool {
		return (
			$node instanceof Comment ||
			$node instanceof Element &&
				in_array( strtolower( $node->tagName ), self::$noElementChildrenElementTypes )
		);
	}

	/**
	 * Check whether the node is a comment separator (instead of a part of the comment).
	 *
	 * @param Node $node
	 * @return bool
	 */
	public static function isCommentSeparator( Node $node ): bool {
		return $node instanceof Element && (
			// Empty paragraphs (`<p><br></p>`) between indented comments mess up indentation detection
			strtolower( $node->nodeName ) === 'br' ||
			// Horizontal line
			strtolower( $node->nodeName ) === 'hr' ||
			// {{outdent}} templates
			DOMCompat::getClassList( $node )->contains( 'outdent-template' )
		);
	}

	/**
	 * Check whether the node is a comment content. It's a little vague what this means…
	 *
	 * @param Node $node Node, should be a leaf node (a node with no children)
	 * @return bool
	 */
	public static function isCommentContent( Node $node ) {
		return (
			$node->nodeType === XML_TEXT_NODE &&
			self::htmlTrim( $node->nodeValue ?? '' ) !== ''
		) ||
		(
			$node->nodeType === XML_CDATA_SECTION_NODE &&
			self::htmlTrim( $node->nodeValue ?? '' ) !== ''
		) ||
		(
			self::cantHaveElementChildren( $node )
		);
	}

	/**
	 * Get the index of $child in its parent
	 *
	 * @param Node $child
	 * @return int
	 */
	public static function childIndexOf( Node $child ): int {
		$i = 0;
		while ( ( $child = $child->previousSibling ) ) {
			$i++;
		}
		return $i;
	}

	/**
	 * Check whether a Node contains (is an ancestor of) another Node (or is the same node)
	 *
	 * @param Node $ancestor
	 * @param Node $descendant
	 * @return bool
	 */
	public static function contains( Node $ancestor, Node $descendant ): bool {
		// TODO can we use Node->compareDocumentPosition() here maybe?
		$node = $descendant;
		while ( $node && $node !== $ancestor ) {
			$node = $node->parentNode;
		}
		return $node === $ancestor;
	}

	/**
	 * Find closest ancestor element using one of the given tag names.
	 *
	 * @param Node $node
	 * @param string[] $tagNames
	 * @return Element|null
	 */
	public static function closestElement( Node $node, array $tagNames ): ?Element {
		do {
			if (
				$node->nodeType === XML_ELEMENT_NODE &&
				in_array( strtolower( $node->nodeName ), $tagNames )
			) {
				// @phan-suppress-next-line PhanTypeMismatchReturn
				return $node;
			}
			$node = $node->parentNode;
		} while ( $node );
		return null;
	}

	/**
	 * Find the transclusion node which rendered the current node, if it exists.
	 *
	 * 1. Find the closest ancestor with an 'about' attribute
	 * 2. Find the main node of the about-group (first sibling with the same 'about' attribute)
	 * 3. If this is an mw:Transclusion node, return it; otherwise, go to step 1
	 *
	 * @param Node $node
	 * @return Element|null Translcusion node, null if not found
	 */
	public static function getTranscludedFromElement( Node $node ): ?Element {
		while ( $node ) {
			// 1.
			if (
				$node instanceof Element &&
				$node->getAttribute( 'about' ) &&
				preg_match( '/^#mwt\d+$/', $node->getAttribute( 'about' ) ?? '' )
			) {
				$about = $node->getAttribute( 'about' );

				// 2.
				while (
					( $previousSibling = $node->previousSibling ) &&
					$previousSibling instanceof Element &&
					$previousSibling->getAttribute( 'about' ) === $about
				) {
					$node = $previousSibling;
				}

				// 3.
				if (
					$node->getAttribute( 'typeof' ) &&
					in_array( 'mw:Transclusion', explode( ' ', $node->getAttribute( 'typeof' ) ?? '' ) )
				) {
					break;
				}
			}

			$node = $node->parentNode;
		}
		return $node;
	}

	/**
	 * Given a heading node, return the node on which the ID attribute is set.
	 *
	 * Also returns the offset within that node where the heading text starts.
	 *
	 * @param Element $heading Heading node (`<h1>`-`<h6>`)
	 * @return array Array containing a 'node' (Element) and offset (int)
	 */
	public static function getHeadlineNodeAndOffset( Element $heading ): array {
		// This code assumes that $wgFragmentMode is [ 'html5', 'legacy' ] or [ 'html5' ]
		$headline = $heading;
		$offset = 0;

		if ( $headline->hasAttribute( 'data-mw-comment-start' ) ) {
			$headline = $headline->parentNode;
		}

		if ( !$headline->getAttribute( 'id' ) ) {
			// PHP HTML: Find the child with .mw-headline
			$headline = $headline->firstChild;
			while (
				$headline && !(
					$headline instanceof Element && DOMCompat::getClassList( $headline )->contains( 'mw-headline' )
				)
			) {
				$headline = $headline->nextSibling;
			}
			if ( $headline ) {
				if (
					( $firstChild = $headline->firstChild ) instanceof Element &&
					DOMCompat::getClassList( $firstChild )->contains( 'mw-headline-number' )
				) {
					$offset = 1;
				}
			} else {
				$headline = $heading;
			}
		}

		return [
			'node' => $headline,
			'offset' => $offset,
		];
	}

	/**
	 * Trim ASCII whitespace, as defined in the HTML spec.
	 *
	 * @param string $str
	 * @return string
	 */
	public static function htmlTrim( string $str ): string {
		// https://infra.spec.whatwg.org/#ascii-whitespace
		return trim( $str, "\t\n\f\r " );
	}

	/**
	 * Get the indent level of $node, relative to $rootNode.
	 *
	 * The indent level is the number of lists inside of which it is nested.
	 *
	 * @param Node $node
	 * @param Node $rootNode
	 * @return int
	 */
	public static function getIndentLevel( Node $node, Node $rootNode ): int {
		$indent = 0;
		while ( $node ) {
			if ( $node === $rootNode ) {
				break;
			}
			$nodeName = strtolower( $node->nodeName );
			if ( $nodeName === 'li' || $nodeName === 'dd' ) {
				$indent++;
			}
			$node = $node->parentNode;
		}
		return $indent;
	}

	/**
	 * Get an array of sibling nodes that contain parts of the given range.
	 *
	 * @param ImmutableRange $range
	 * @return Element[]
	 */
	public static function getCoveredSiblings( ImmutableRange $range ): array {
		$ancestor = $range->commonAncestorContainer;

		// Convert to array early because apparently NodeList acts like a linked list
		// and accessing items by index is slow
		$siblings = iterator_to_array( $ancestor->childNodes );
		$start = 0;
		$end = count( $siblings ) - 1;

		// Find first of the siblings that contains the item
		if ( $ancestor === $range->startContainer ) {
			$start = $range->startOffset;
		} else {
			while ( !self::contains( $siblings[ $start ], $range->startContainer ) ) {
				$start++;
			}
		}

		// Find last of the siblings that contains the item
		if ( $ancestor === $range->endContainer ) {
			$end = $range->endOffset - 1;
		} else {
			while ( !self::contains( $siblings[ $end ], $range->endContainer ) ) {
				$end--;
			}
		}

		return array_slice( $siblings, $start, $end - $start + 1 );
	}

	/**
	 * Get the nodes (if any) that contain the given thread item, and nothing else.
	 *
	 * @param ThreadItem $item
	 * @return Element[]|null
	 */
	public static function getFullyCoveredSiblings( ThreadItem $item ): ?array {
		$siblings = self::getCoveredSiblings( $item->getRange() );

		$makeRange = static function ( $siblings ) {
			return new ImmutableRange(
				$siblings[0]->parentNode,
				CommentUtils::childIndexOf( $siblings[0] ),
				end( $siblings )->parentNode,
				CommentUtils::childIndexOf( end( $siblings ) ) + 1
			);
		};

		$matches = self::compareRanges( $makeRange( $siblings ), $item->getRange() ) === 'equal';

		if ( $matches ) {
			// If these are all of the children (or the only child), go up one more level
			while (
				( $parent = $siblings[ 0 ]->parentNode ) &&
				self::compareRanges( $makeRange( [ $parent ] ), $item->getRange() ) === 'equal'
			) {
				$siblings = [ $parent ];
			}
			return $siblings;
		}
		return null;
	}

	/**
	 * Unwrap Parsoid sections
	 *
	 * @param Element $element Parent element, e.g. document body
	 * @param string|null $keepSection Section to keep
	 */
	public static function unwrapParsoidSections(
		Element $element, string $keepSection = null
	): void {
		$sections = DOMCompat::querySelectorAll( $element, 'section[data-mw-section-id]' );
		foreach ( $sections as $section ) {
			$parent = $section->parentNode;
			$sectionId = $section->getAttribute( 'data-mw-section-id' );
			// Copy section ID to first child (should be a heading)
			if ( $sectionId !== null && $sectionId !== '' && intval( $sectionId ) > 0 ) {
				$firstChild = $section->firstChild;
				Assert::precondition( $firstChild instanceof Element, 'Section has a heading' );
				$firstChild->setAttribute( 'data-mw-section-id', $sectionId );
			}
			if ( $keepSection !== null && $sectionId === $keepSection ) {
				return;
			}
			while ( $section->firstChild ) {
				$parent->insertBefore( $section->firstChild, $section );
			}
			$parent->removeChild( $section );
		}
	}

	/**
	 * Get a MediaWiki page title from a URL
	 *
	 * @param string $url
	 * @return Title|null
	 */
	public static function getTitleFromUrl( string $url ): ?Title {
		$bits = parse_url( $url );
		$query = wfCgiToArray( $bits['query'] ?? '' );
		if ( isset( $query['title'] ) ) {
			return Title::newFromText( $query['title'] );
		}

		$config = MediaWikiServices::getInstance()->getMainConfig();
		// TODO: Set the correct base in the document?
		if ( strpos( $url, './' ) === 0 ) {
			$url = 'https://local' . str_replace( '$1', substr( $url, 2 ), $config->get( 'ArticlePath' ) );
		} elseif ( strpos( $url, '://' ) === false ) {
			$url = 'https://local' . $url;
		}

		$articlePathRegexp = '/' . str_replace(
			preg_quote( '$1', '/' ),
			'(.*)',
			preg_quote( $config->get( 'ArticlePath' ), '/' )
		) . '/';
		$matches = null;
		if ( preg_match( $articlePathRegexp, $url, $matches ) ) {
			return Title::newFromText( urldecode( $matches[1] ) );
		}
		return null;
	}

	/**
	 * Traverse the document in depth-first order, calling the callback whenever entering and leaving
	 * a node. The walk starts before the given node and ends when callback returns a truthy value, or
	 * after reaching the end of the document.
	 *
	 * You might also think about this as processing XML token stream linearly (rather than XML
	 * nodes), as if we were parsing the document.
	 *
	 * @param Node $node Node to start at
	 * @param callable $callback Function accepting two arguments: $event ('enter' or 'leave') and
	 *     $node (Node)
	 * @return mixed Final return value of the callback
	 */
	public static function linearWalk( Node $node, callable $callback ) {
		$result = null;
		[ $withinNode, $beforeNode ] = [ $node->parentNode, $node ];

		while ( $beforeNode || $withinNode ) {
			if ( $beforeNode ) {
				$result = $callback( 'enter', $beforeNode );
				[ $withinNode, $beforeNode ] = [ $beforeNode, $beforeNode->firstChild ];
			} else {
				$result = $callback( 'leave', $withinNode );
				[ $withinNode, $beforeNode ] = [ $withinNode->parentNode, $withinNode->nextSibling ];
			}

			if ( $result ) {
				return $result;
			}
		}
		return $result;
	}

	/**
	 * Like #linearWalk, but it goes backwards.
	 *
	 * @inheritDoc ::linearWalk()
	 */
	public static function linearWalkBackwards( Node $node, callable $callback ) {
		$result = null;
		[ $withinNode, $beforeNode ] = [ $node->parentNode, $node ];

		while ( $beforeNode || $withinNode ) {
			if ( $beforeNode ) {
				$result = $callback( 'enter', $beforeNode );
				[ $withinNode, $beforeNode ] = [ $beforeNode, $beforeNode->lastChild ];
			} else {
				$result = $callback( 'leave', $withinNode );
				[ $withinNode, $beforeNode ] = [ $withinNode->parentNode, $withinNode->previousSibling ];
			}

			if ( $result ) {
				return $result;
			}
		}
		return $result;
	}

	/**
	 * @param ImmutableRange $range (must not be collapsed)
	 * @return Node
	 */
	public static function getRangeFirstNode( ImmutableRange $range ): Node {
		Assert::precondition( !$range->collapsed, 'Range is not collapsed' );
		// PHP bug: childNodes can be null
		return $range->startContainer->childNodes && $range->startContainer->childNodes->length ?
			$range->startContainer->childNodes[ $range->startOffset ] :
			$range->startContainer;
	}

	/**
	 * @param ImmutableRange $range (must not be collapsed)
	 * @return Node
	 */
	public static function getRangeLastNode( ImmutableRange $range ): Node {
		Assert::precondition( !$range->collapsed, 'Range is not collapsed' );
		// PHP bug: childNodes can be null
		return $range->endContainer->childNodes && $range->endContainer->childNodes->length ?
			$range->endContainer->childNodes[ $range->endOffset - 1 ] :
			$range->endContainer;
	}

	/**
	 * Check whether two ranges overlap, and how.
	 *
	 * Includes a hack to check for "almost equal" ranges (whose start/end boundaries only differ by
	 * "uninteresting" nodes that we ignore when detecting comments), and treat them as equal.
	 *
	 * Illustration of return values:
	 *          [    equal    ]
	 *          |[ contained ]|
	 *        [ |  contains   | ]
	 *  [overlap|start]       |
	 *          |     [overlap|end]
	 * [before] |             |
	 *          |             | [after]
	 *
	 * @param ImmutableRange $a
	 * @param ImmutableRange $b
	 * @return string One of:
	 *     - 'equal': Ranges A and B are equal
	 *     - 'contains': Range A contains range B
	 *     - 'contained': Range A is contained within range B
	 *     - 'after': Range A is before range B
	 *     - 'before': Range A is after range B
	 *     - 'overlapstart': Start of range A overlaps range B
	 *     - 'overlapend': End of range A overlaps range B
	 */
	public static function compareRanges( ImmutableRange $a, ImmutableRange $b ): string {
		// Compare the positions of: start of A to start of B, start of A to end of B, and so on.
		// Watch out, the constant names are the opposite of what they should be.
		$startToStart = $a->compareBoundaryPoints( ImmutableRange::START_TO_START, $b );
		$startToEnd = $a->compareBoundaryPoints( ImmutableRange::END_TO_START, $b );
		$endToStart = $a->compareBoundaryPoints( ImmutableRange::START_TO_END, $b );
		$endToEnd = $a->compareBoundaryPoints( ImmutableRange::END_TO_END, $b );

		// Handle almost equal ranges: When start or end boundary points of the two ranges are different,
		// but only differ by "uninteresting" nodes, treat them as equal instead.
		if (
			( $startToStart < 0 && self::compareRangesAlmostEqualBoundaries( $a, $b, 'start' ) ) ||
			( $startToStart > 0 && self::compareRangesAlmostEqualBoundaries( $b, $a, 'start' ) )
		) {
			$startToStart = 0;
		}
		if (
			( $endToEnd < 0 && self::compareRangesAlmostEqualBoundaries( $a, $b, 'end' ) ) ||
			( $endToEnd > 0 && self::compareRangesAlmostEqualBoundaries( $b, $a, 'end' ) )
		) {
			$endToEnd = 0;
		}

		if ( $startToStart === 0 && $endToEnd === 0 ) {
			return 'equal';
		}
		if ( $startToStart <= 0 && $endToEnd >= 0 ) {
			return 'contains';
		}
		if ( $startToStart >= 0 && $endToEnd <= 0 ) {
			return 'contained';
		}
		if ( $startToEnd >= 0 ) {
			return 'after';
		}
		if ( $endToStart <= 0 ) {
			return 'before';
		}
		if ( $startToStart > 0 && $startToEnd < 0 && $endToEnd >= 0 ) {
			return 'overlapstart';
		}
		if ( $endToEnd < 0 && $endToStart > 0 && $startToStart <= 0 ) {
			return 'overlapend';
		}

		throw new LogicException( 'Unreachable' );
	}

	/**
	 * Check if the given boundary points of ranges A and B are almost equal (only differing by
	 * uninteresting nodes).
	 *
	 * Boundary of A must be before the boundary of B in the tree.
	 *
	 * @param ImmutableRange $a
	 * @param ImmutableRange $b
	 * @param string $boundary 'start' or 'end'
	 * @return bool
	 */
	private static function compareRangesAlmostEqualBoundaries(
		ImmutableRange $a, ImmutableRange $b, string $boundary
	): bool {
		// This code is awful, but several attempts to rewrite it made it even worse.
		// You're welcome to give it a try.

		$from = $boundary === 'end' ? self::getRangeLastNode( $a ) : self::getRangeFirstNode( $a );
		$to = $boundary === 'end' ? self::getRangeLastNode( $b ) : self::getRangeFirstNode( $b );

		$skipNode = null;
		if ( $boundary === 'end' ) {
			$skipNode = $from;
		}

		$foundContent = false;
		self::linearWalk(
			$from,
			static function ( string $event, Node $n ) use (
				$from, $to, $boundary, &$skipNode, &$foundContent
			) {
				if ( $n === $to && $event === ( $boundary === 'end' ? 'leave' : 'enter' ) ) {
					return true;
				}
				if ( $skipNode ) {
					if ( $n === $skipNode && $event === 'leave' ) {
						$skipNode = null;
					}
					return;
				}

				if ( $event === 'enter' ) {
					if (
						CommentUtils::isCommentSeparator( $n ) ||
						CommentUtils::isRenderingTransparentNode( $n ) ||
						CommentUtils::isOurGeneratedNode( $n )
					) {
						$skipNode = $n;

					} elseif (
						CommentUtils::isCommentContent( $n )
					) {
						$foundContent = true;
						return true;
					}
				}
			}
		);

		return !$foundContent;
	}
}
