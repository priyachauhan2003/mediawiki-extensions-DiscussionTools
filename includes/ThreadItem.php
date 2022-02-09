<?php

namespace MediaWiki\Extension\DiscussionTools;

use JsonSerializable;
use LogicException;
use Title;
use Wikimedia\Parsoid\DOM\Element;
use Wikimedia\Parsoid\DOM\Node;
use Wikimedia\Parsoid\Utils\DOMUtils;

/**
 * A thread item, either a heading or a comment
 */
abstract class ThreadItem implements JsonSerializable {
	protected $type;
	protected $range;
	protected $rootNode;
	protected $level;
	protected $parent;
	protected $warnings = [];

	protected $name = null;
	protected $id = null;
	protected $legacyId = null;
	protected $replies = [];

	/**
	 * @param string $type `heading` or `comment`
	 * @param int $level Indentation level
	 * @param ImmutableRange $range Object describing the extent of the comment, including the
	 *  signature and timestamp.
	 */
	public function __construct(
		string $type, int $level, ImmutableRange $range
	) {
		$this->type = $type;
		$this->level = $level;
		$this->range = $range;
	}

	/**
	 * @return array JSON-serializable array
	 */
	public function jsonSerialize(): array {
		// The output of this method can end up in the HTTP cache (Varnish). Avoid changing it;
		// and when doing so, ensure that frontend code can handle both the old and new outputs.
		// See ThreadItem.static.newFromJSON in JS.

		return [
			'type' => $this->type,
			'level' => $this->level,
			'id' => $this->id,
			'replies' => array_map( static function ( ThreadItem $comment ) {
				return $comment->getId();
			}, $this->replies )
		];
	}

	/**
	 * Get the list of authors in the comment tree below this thread item.
	 *
	 * Usually called on a HeadingItem to find all authors in a thread.
	 *
	 * @return string[] Author usernames
	 */
	public function getAuthorsBelow(): array {
		$authors = [];
		$getAuthorSet = static function ( ThreadItem $comment ) use ( &$authors, &$getAuthorSet ) {
			if ( $comment instanceof CommentItem ) {
				$authors[ $comment->getAuthor() ] = true;
			}
			// Get the set of authors in the same format from each reply
			foreach ( $comment->getReplies() as $reply ) {
				$getAuthorSet( $reply );
			}
		};

		foreach ( $this->getReplies() as $reply ) {
			$getAuthorSet( $reply );
		}

		ksort( $authors );
		return array_keys( $authors );
	}

	/**
	 * Get the name of the page from which this thread item is transcluded (if any). Replies to
	 * transcluded items must be posted on that page, instead of the current one.
	 *
	 * This is tricky, because we don't want to mark items as trancluded when they're just using a
	 * template (e.g. {{ping|…}} or a non-substituted signature template). Sometimes the whole comment
	 * can be template-generated (e.g. when using some wrapper templates), but as long as a reply can
	 * be added outside of that template, we should not treat it as transcluded.
	 *
	 * The start/end boundary points of comment ranges and Parsoid transclusion ranges don't line up
	 * exactly, even when to a human it's obvious that they cover the same content, making this more
	 * complicated.
	 *
	 * @return string|bool `false` if this item is not transcluded. A string if it's transcluded
	 *   from a single page (the page title, in text form with spaces). `true` if it's transcluded, but
	 *   we can't determine the source.
	 */
	public function getTranscludedFrom() {
		// General approach:
		//
		// Compare the comment range to each transclusion range on the page, and if it overlaps any of
		// them, examine the overlap. There are a few cases:
		//
		// * Comment and transclusion do not overlap:
		//   → Not transcluded.
		// * Comment contains the transclusion:
		//   → Not transcluded (just a template).
		// * Comment is contained within the transclusion:
		//   → Transcluded, we can determine the source page (unless it's a complex transclusion).
		// * Comment and transclusion overlap partially:
		//   → Transcluded, but we can't determine the source page.
		// * Comment (almost) exactly matches the transclusion:
		//   → Maybe transcluded (it could be that the source page only contains that single comment),
		//     maybe not transcluded (it could be a wrapper template that covers a single comment).
		//     This is very sad, and we decide based on the namespace.
		//
		// Most transclusion ranges on the page trivially fall in the "do not overlap" or "contains"
		// cases, and we only have to carefully examine the two transclusion ranges that contain the
		// first and last node of the comment range.
		//
		// To check for almost exact matches, we walk between the relevant boundary points, and if we
		// only find uninteresting nodes (that would be ignored when detecting comments), we treat them
		// like exact matches.

		$commentRange = $this->getRange();
		$startTransclNode = CommentUtils::getTranscludedFromElement(
			CommentUtils::getRangeFirstNode( $commentRange )
		);
		$endTransclNode = CommentUtils::getTranscludedFromElement(
			CommentUtils::getRangeLastNode( $commentRange )
		);

		// We only have to examine the two transclusion ranges that contain the first/last node of the
		// comment range (if they exist). Ignore ranges outside the comment or in the middle of it.
		$transclNodes = [];
		if ( $startTransclNode ) {
			$transclNodes[] = $startTransclNode;
		}
		if ( $endTransclNode && $endTransclNode !== $startTransclNode ) {
			$transclNodes[] = $endTransclNode;
		}

		foreach ( $transclNodes as $transclNode ) {
			$transclRange = self::getTransclusionRange( $transclNode );
			$compared = CommentUtils::compareRanges( $commentRange, $transclRange );
			$transclTitle = $this->getSinglePageTransclusionTitle( $transclNode );

			switch ( $compared ) {
				case 'equal':
					// Comment (almost) exactly matches the transclusion
					if ( $transclTitle === null ) {
						// Multi-template transclusion, or a parser function call, or template-affected wikitext outside
						// of a template call, or a mix of the above
						return true;
					} elseif ( $transclTitle->inNamespace( NS_TEMPLATE ) ) {
						// Is that a subpage transclusion with a single comment, or a wrapper template
						// transclusion on this page? We don't know, but let's guess based on the namespace.
						// (T289873)
						// Continue examining the other ranges.
						break;
					} else {
						return $transclTitle->getPrefixedText();
					}

				case 'contains':
					// Comment contains the transclusion

					// If the entire transclusion is contained within the comment range, that's just a
					// template. This is the same as a transclusion in the middle of the comment, which we
					// ignored earlier, it just takes us longer to get here in this case.

					// Continue examining the other ranges.
					break;

				case 'contained':
					// Comment is contained within the transclusion
					if ( $transclTitle === null ) {
						return true;
					} else {
						return $transclTitle->getPrefixedText();
					}

				case 'after':
				case 'before':
					// Comment and transclusion do not overlap

					// This should be impossible, because we ignored these ranges earlier.
					throw new LogicException( 'Unexpected transclusion or comment range' );

				case 'overlapstart':
				case 'overlapend':
					// Comment and transclusion overlap partially
					return true;

				default:
					throw new LogicException( 'Unexpected return value from compareRanges()' );
			}
		}

		// If we got here, the comment range was not contained by or overlapping any of the transclusion
		// ranges. Comment is not transcluded.
		return false;
	}

	/**
	 * If the node represents a single-page transclusion, return that page's title.
	 * Otherwise return null.
	 *
	 * @param Element $node
	 * @return Title|null
	 */
	private function getSinglePageTransclusionTitle( Element $node ) {
		$dataMw = json_decode( $node->getAttribute( 'data-mw' ) ?? '', true );

		// Only return a page name if this is a simple single-template transclusion.
		if (
			is_array( $dataMw ) &&
			$dataMw['parts'] &&
			count( $dataMw['parts'] ) === 1 &&
			$dataMw['parts'][0]['template'] &&
			// 'href' will be unset if this is a parser function rather than a template
			isset( $dataMw['parts'][0]['template']['target']['href'] )
		) {
			$title = CommentUtils::getTitleFromUrl( $dataMw['parts'][0]['template']['target']['href'] );
			return $title;
		}

		return null;
	}

	/**
	 * Given a transclusion's first node (e.g. returned by CommentUtils::getTranscludedFromElement()),
	 * return a range starting before the node and ending after the transclusion's last node.
	 *
	 * @param Element $startNode
	 * @return ImmutableRange
	 */
	private function getTransclusionRange( Element $startNode ) {
		$endNode = $startNode;
		while (
			// Phan doesn't realize that the conditions on $nextSibling can terminate the loop
			// @phan-suppress-next-line PhanInfiniteLoop
			$endNode &&
			( $nextSibling = $endNode->nextSibling ) &&
			$nextSibling instanceof Element &&
			$nextSibling->getAttribute( 'about' ) === $endNode->getAttribute( 'about' )
		) {
			$endNode = $nextSibling;
		}

		$range = new ImmutableRange(
			$startNode->parentNode,
			CommentUtils::childIndexOf( $startNode ),
			$endNode->parentNode,
			CommentUtils::childIndexOf( $endNode ) + 1
		);

		return $range;
	}

	/**
	 * Get the HTML of this thread item
	 *
	 * @return string HTML
	 */
	public function getHTML(): string {
		$fragment = $this->getRange()->cloneContents();
		CommentModifier::unwrapFragment( $fragment );
		return DOMUtils::getFragmentInnerHTML( $fragment );
	}

	/**
	 * Get the text of this thread item
	 *
	 * @return string Text
	 */
	public function getText(): string {
		$fragment = $this->getRange()->cloneContents();
		return $fragment->textContent ?? '';
	}

	/**
	 * @return string Thread item type
	 */
	public function getType(): string {
		return $this->type;
	}

	/**
	 * @return int Indentation level
	 */
	public function getLevel(): int {
		return $this->level;
	}

	/**
	 * @return ThreadItem|null Parent thread item
	 */
	public function getParent(): ?ThreadItem {
		return $this->parent;
	}

	/**
	 * @return ImmutableRange Range of the entire thread item
	 */
	public function getRange(): ImmutableRange {
		return $this->range;
	}

	/**
	 * @return Node Root node (level is relative to this node)
	 */
	public function getRootNode(): Node {
		return $this->rootNode;
	}

	/**
	 * @return string Thread item name
	 */
	public function getName(): string {
		return $this->name;
	}

	/**
	 * @return string Thread ID
	 */
	public function getId(): string {
		return $this->id;
	}

	/**
	 * @return string|null Thread ID, according to an older algorithm
	 */
	public function getLegacyId(): ?string {
		return $this->legacyId;
	}

	/**
	 * @return ThreadItem[] Replies to this thread item
	 */
	public function getReplies(): array {
		return $this->replies;
	}

	/**
	 * @return string[] Warnings
	 */
	public function getWarnings(): array {
		return $this->warnings;
	}

	/**
	 * @param int $level Indentation level
	 */
	public function setLevel( int $level ): void {
		$this->level = $level;
	}

	/**
	 * @param ThreadItem $parent
	 */
	public function setParent( ThreadItem $parent ): void {
		$this->parent = $parent;
	}

	/**
	 * @param ImmutableRange $range Thread item range
	 */
	public function setRange( ImmutableRange $range ): void {
		$this->range = $range;
	}

	/**
	 * @param Node $rootNode Root node (level is relative to this node)
	 */
	public function setRootNode( Node $rootNode ): void {
		$this->rootNode = $rootNode;
	}

	/**
	 * @param string|null $name Thread item name
	 */
	public function setName( ?string $name ): void {
		$this->name = $name;
	}

	/**
	 * @param string|null $id Thread ID
	 */
	public function setId( ?string $id ): void {
		$this->id = $id;
	}

	/**
	 * @param string|null $id Thread ID
	 */
	public function setLegacyId( ?string $id ): void {
		$this->legacyId = $id;
	}

	/**
	 * @param string $warning
	 */
	public function addWarning( string $warning ): void {
		$this->warnings[] = $warning;
	}

	/**
	 * @param string[] $warnings
	 */
	public function addWarnings( array $warnings ): void {
		$this->warnings = array_merge( $this->warnings, $warnings );
	}

	/**
	 * @param ThreadItem $reply Reply comment
	 */
	public function addReply( ThreadItem $reply ): void {
		$this->replies[] = $reply;
	}
}
