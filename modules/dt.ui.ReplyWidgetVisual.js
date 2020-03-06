var
	CommentTargetWidget = require( './CommentTargetWidget.js' );

/**
 * DiscussionTools ReplyWidgetVisual class
 *
 * @class mw.dt.ReplyWidgetVisual
 * @extends mw.dt.ReplyWidget
 * @constructor
 * @param {Object} comment Parsed comment object
 * @param {Object} [config] Configuration options
 */
function ReplyWidgetVisual( comment, config ) {
	// Parent constructor
	ReplyWidgetVisual.super.call( this, comment, config );
}

/* Inheritance */

OO.inheritClass( ReplyWidgetVisual, require( 'ext.discussionTools.ReplyWidget' ) );

/* Methods */

ReplyWidgetVisual.prototype.createReplyBodyWidget = function ( config ) {
	return new CommentTargetWidget( $.extend( {
		defaultMode: 'source'
	}, config ) );
};

ReplyWidgetVisual.prototype.getValue = function () {
	if ( this.getMode() === 'source' ) {
		return this.replyBodyWidget.target.getSurface().getModel().getDom();
	} else {
		return this.replyBodyWidget.target.getSurface().getHtml();
	}
};

// TODO: Implement getMode to get current mode from surface

ReplyWidgetVisual.prototype.clear = function () {
	// Parent method
	ReplyWidgetVisual.super.prototype.clear.apply( this, arguments );

	this.replyBodyWidget.clear();
};

ReplyWidgetVisual.prototype.isEmpty = function () {
	var surface = this.replyBodyWidget.target.getSurface();
	return !surface || !surface.getModel().hasBeenModified();
};

ReplyWidgetVisual.prototype.setup = function () {
	// Parent method
	ReplyWidgetVisual.super.prototype.setup.call( this );

	this.replyBodyWidget.setDocument( '<p></p>' );

	this.mode = this.replyBodyWidget.target.getSurface().getMode();

	// Events
	this.replyBodyWidget.target.getSurface().getModel().getDocument().connect( this, { transact: this.onInputChangeThrottled } )
		.once( 'transact', this.onFirstTransaction.bind( this ) );
	this.replyBodyWidget.target.getSurface().connect( this, { submit: 'onReplyClick' } );
};

ReplyWidgetVisual.prototype.focus = function () {
	var targetWidget = this.replyBodyWidget;
	setTimeout( function () {
		targetWidget.getSurface().getModel().selectLastContentOffset();
		targetWidget.focus();
	} );
};

ReplyWidgetVisual.prototype.setPending = function ( pending ) {
	ReplyWidgetVisual.super.prototype.setPending.call( this, pending );

	if ( pending ) {
		// TODO
		// this.replyBodyWidget.pushPending();
		this.replyBodyWidget.setReadOnly( true );
	} else {
		// this.replyBodyWidget.popPending();
		this.replyBodyWidget.setReadOnly( false );
	}
};

module.exports = ReplyWidgetVisual;
