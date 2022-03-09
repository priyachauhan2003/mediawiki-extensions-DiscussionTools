var utils = require( 'ext.discussionTools.init' ).utils;

module.exports = {};

/**
 * Override mw.config with the given data. Used for testing different languages etc.
 * (Automatically restored after every test by QUnit.newMwEnvironment.)
 *
 * @param {Object} config
 */
module.exports.overrideMwConfig = function ( config ) {
	$.extend(
		mw.config.values,
		config
	);
};

/**
 * Return the node that is expected to contain thread items.
 *
 * @param {jQuery} $nodes
 * @return {jQuery}
 */
module.exports.getThreadContainer = function ( $nodes ) {
	// In tests created from Parsoid output, comments are contained directly in <body>. This becomes a
	// huge mess of <section> nodes when we insert it into the existing document, oh well…
	// In tests created from old parser output, comments are contained in <div class="mw-parser-output">.
	if ( $nodes.filter( 'section' ).length ) {
		return $( '<div>' )
			.append( $nodes.filter( 'section' ) )
			.append( $nodes.filter( 'base' ) );
	} else {
		return $nodes.find( 'div.mw-parser-output' );
	}
};

/**
 * Get the offset path from ancestor to offset in descendant
 *
 * @copyright 2011-2019 VisualEditor Team and others; see http://ve.mit-license.org
 *
 * @param {Node} ancestor The ancestor node
 * @param {Node} node The descendant node
 * @param {number} nodeOffset The offset in the descendant node
 * @return {number[]} The offset path
 */
function getOffsetPath( ancestor, node, nodeOffset ) {
	var path = [ nodeOffset ];
	while ( node !== ancestor ) {
		if ( node.parentNode === null ) {
			// eslint-disable-next-line no-console
			console.log( node, 'is not a descendant of', ancestor );
			throw new Error( 'Not a descendant' );
		}
		path.unshift( utils.childIndexOf( node ) );
		node = node.parentNode;
	}
	return path;
}

/**
 * Massage comment data to make it serializable as JSON.
 *
 * @param {CommentItem} parent Comment item; modified in-place
 * @param {Node} root Ancestor node of all comments
 */
module.exports.serializeComments = function ( parent, root ) {
	if ( !parent.range.startContainer ) {
		// Already done as part of a different thread
		return;
	}

	// Can't serialize circular structures to JSON
	delete parent.parent;

	// Can't serialize the DOM nodes involved in the range,
	// instead use their offsets within their parent nodes
	parent.range = [
		getOffsetPath( root, parent.range.startContainer, parent.range.startOffset ).join( '/' ),
		getOffsetPath( root, parent.range.endContainer, parent.range.endOffset ).join( '/' )
	];
	if ( parent.signatureRanges ) {
		parent.signatureRanges = parent.signatureRanges.map( function ( range ) {
			return [
				getOffsetPath( root, range.startContainer, range.startOffset ).join( '/' ),
				getOffsetPath( root, range.endContainer, range.endOffset ).join( '/' )
			];
		} );
	}

	// Unimportant
	delete parent.rootNode;

	delete parent.legacyId;

	parent.replies.forEach( function ( comment ) {
		module.exports.serializeComments( comment, root );
	} );
};
