<?php
/**
 * DiscussionTools page hooks
 *
 * @file
 * @ingroup Extensions
 * @license MIT
 */

namespace MediaWiki\Extension\DiscussionTools\Hooks;

use Article;
use ConfigFactory;
use Html;
use IContextSource;
use MediaWiki\Actions\Hook\GetActionNameHook;
use MediaWiki\Extension\DiscussionTools\CommentFormatter;
use MediaWiki\Extension\DiscussionTools\SubscriptionStore;
use MediaWiki\Hook\BeforePageDisplayHook;
use MediaWiki\Hook\OutputPageBeforeHTMLHook;
use MediaWiki\Hook\TitleGetEditNoticesHook;
use MediaWiki\Page\Hook\ArticleViewHeaderHook;
use MediaWiki\Page\Hook\BeforeDisplayNoArticleTextHook;
use MediaWiki\User\UserNameUtils;
use MediaWiki\User\UserOptionsLookup;
use OOUI\ButtonWidget;
use OOUI\HtmlSnippet;
use OOUI\MessageWidget;
use OutputPage;
use ParserOutput;
use RequestContext;
use Skin;
use SpecialPage;
use Title;
use VisualEditorHooks;

class PageHooks implements
	ArticleViewHeaderHook,
	BeforeDisplayNoArticleTextHook,
	BeforePageDisplayHook,
	GetActionNameHook,
	OutputPageBeforeHTMLHook,
	TitleGetEditNoticesHook
{
	/** @var ConfigFactory */
	private $configFactory;

	/** @var SubscriptionStore */
	private $subscriptionStore;

	/** @var UserNameUtils */
	private $userNameUtils;

	/** @var UserOptionsLookup */
	private $userOptionsLookup;

	/**
	 * @param ConfigFactory $configFactory
	 * @param SubscriptionStore $subscriptionStore
	 * @param UserNameUtils $userNameUtils
	 * @param UserOptionsLookup $userOptionsLookup
	 */
	public function __construct(
		ConfigFactory $configFactory,
		SubscriptionStore $subscriptionStore,
		UserNameUtils $userNameUtils,
		UserOptionsLookup $userOptionsLookup
	) {
		$this->configFactory = $configFactory;
		$this->subscriptionStore = $subscriptionStore;
		$this->userNameUtils = $userNameUtils;
		$this->userOptionsLookup = $userOptionsLookup;
	}

	/**
	 * Adds DiscussionTools JS to the output.
	 *
	 * This is attached to the MediaWiki 'BeforePageDisplay' hook.
	 *
	 * @param OutputPage $output
	 * @param Skin $skin
	 * @return void This hook must not abort, it must return no value
	 */
	public function onBeforePageDisplay( $output, $skin ): void {
		$user = $output->getUser();
		$req = $output->getRequest();
		foreach ( HookUtils::FEATURES as $feature ) {
			// Add a CSS class for each enabled feature
			if ( HookUtils::isFeatureEnabledForOutput( $output, $feature ) ) {
				$output->addBodyClasses( "ext-discussiontools-$feature-enabled" );
			}
		}

		// Load style modules if the tools can be available for the title
		// to selectively hide DT features, depending on the body classes added above.
		$availableForTitle = HookUtils::isAvailableForTitle( $output->getTitle() );
		if ( $availableForTitle ) {
			$output->addModuleStyles( [
				'ext.discussionTools.init.styles',
			] );
		}

		$dtConfig = $this->configFactory->makeConfig( 'discussiontools' );

		// Load modules if any DT feature is enabled for this user
		if (
			HookUtils::isFeatureEnabledForOutput( $output ) || (
				// logged out users should get the modules when there's an a/b test
				// client-side overrides of the enabling will occur
				$availableForTitle &&
				$dtConfig->get( 'DiscussionToolsABTest' ) &&
				!$user->isRegistered()
			)
		) {
			$output->addModules( [
				'ext.discussionTools.init'
			] );

			$enabledVars = [];
			foreach ( HookUtils::FEATURES as $feature ) {
				$enabledVars[$feature] = HookUtils::isFeatureEnabledForOutput( $output, $feature );
			}
			$output->addJsConfigVars( 'wgDiscussionToolsFeaturesEnabled', $enabledVars );

			$editor = $this->userOptionsLookup->getOption( $user, 'discussiontools-editmode' );
			// User has no preferred editor yet
			// If the user has a preferred editor, this will be evaluated in the client
			if ( !$editor ) {
				// Check which editor we would use for articles
				// VE pref is 'visualeditor'/'wikitext'. Here we describe the mode,
				// not the editor, so 'visual'/'source'
				$editor = VisualEditorHooks::getPreferredEditor( $user, $req ) === 'visualeditor' ?
					'visual' : 'source';
				$output->addJsConfigVars(
					'wgDiscussionToolsFallbackEditMode',
					$editor
				);
			}
			$abstate = $dtConfig->get( 'DiscussionToolsABTest' ) ?
				$this->userOptionsLookup->getOption( $user, 'discussiontools-abtest2' ) :
				false;
			if ( $abstate ) {
				$output->addJsConfigVars(
					'wgDiscussionToolsABTestBucket',
					$abstate
				);
			}
		}

		// Replace the action=edit&section=new form with the new topic tool.
		if ( HookUtils::shouldOpenNewTopicTool( $output->getContext() ) ) {
			$output->addJsConfigVars( 'wgDiscussionToolsStartNewTopicTool', true );

			// For no-JS compatibility, redirect to the old new section editor if JS is unavailable.
			// This isn't great, because the user has to load the page twice. But making a page that is
			// both a view mode and an edit mode seems difficult, so I'm cutting some corners here.
			// (Code below adapted from VisualEditor.)
			$params = $output->getRequest()->getValues();
			$params['dtenable'] = '0';
			$url = wfScript() . '?' . wfArrayToCgi( $params );
			$escapedUrl = htmlspecialchars( $url );

			// Redirect if the user has no JS (<noscript>)
			$output->addHeadItem(
				'dt-noscript-fallback',
				"<noscript><meta http-equiv=\"refresh\" content=\"0; url=$escapedUrl\"></noscript>"
			);
			// Redirect if the user has no ResourceLoader
			$output->addScript( Html::inlineScript(
				"(window.NORLQ=window.NORLQ||[]).push(" .
					"function(){" .
						"location.href=\"$url\";" .
					"}" .
				");"
			) );
		}
	}

	/**
	 * OutputPageBeforeHTML hook handler
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/OutputPageBeforeHTML
	 *
	 * @param OutputPage $output OutputPage object that corresponds to the page
	 * @param string &$text Text that will be displayed, in HTML
	 * @return bool|void This hook must not abort, it must return true or null.
	 */
	public function onOutputPageBeforeHTML( $output, &$text ) {
		// ParserOutputPostCacheTransform hook would be a better place to do this,
		// so that when the ParserOutput is used directly without using this hook,
		// we don't leave half-baked interface elements in it (see e.g. T292345, T294168).
		// But that hook doesn't provide parameters that we need to render correctly
		// (including the page title, interface language, and current user).

		$lang = $output->getLanguage();
		if ( HookUtils::isFeatureEnabledForOutput( $output, HookUtils::TOPICSUBSCRIPTION ) ) {
			$text = CommentFormatter::postprocessTopicSubscription(
				$text, $lang, $this->subscriptionStore, $output->getUser()
			);
		}
		if ( HookUtils::isFeatureEnabledForOutput( $output, HookUtils::REPLYTOOL ) ) {
			$text = CommentFormatter::postprocessReplyTool(
				$text, $lang
			);
		}

		return true;
	}

	/**
	 * GetActionName hook handler
	 *
	 * @param IContextSource $context Request context
	 * @param string &$action Default action name, reassign to change it
	 * @return void This hook must not abort, it must return no value
	 */
	public function onGetActionName( IContextSource $context, string &$action ): void {
		if ( $action === 'edit' && (
			HookUtils::shouldOpenNewTopicTool( $context ) ||
			HookUtils::shouldDisplayEmptyState( $context )
		) ) {
			$action = 'view';
		}
	}

	/**
	 * BeforeDisplayNoArticleText hook handler
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/BeforeDisplayNoArticleText
	 *
	 * @param Article $article The (empty) article
	 * @return bool|void This hook can abort
	 */
	public function onBeforeDisplayNoArticleText( $article ) {
		// We want to override the empty state for articles on which we would be enabled
		$context = $article->getContext();
		if ( !HookUtils::shouldDisplayEmptyState( $context ) ) {
			// Our empty states are all about using the new topic tool, but
			// expect to be on a talk page, so fall back if it's not
			// available or if we're in a non-talk namespace that still has
			// DT features enabled
			return true;
		}

		$output = $context->getOutput();
		$output->enableOOUI();
		$output->disableClientCache();

		$coreConfig = RequestContext::getMain()->getConfig();
		$iconpath = $coreConfig->get( 'ExtensionAssetsPath' ) . '/DiscussionTools/images';

		$dir = $context->getLanguage()->getDir();
		$lang = $context->getLanguage()->getHtmlCode();

		$output->addHTML(
			// This being mw-parser-output is a lie, but makes the reply controller cope much better with everything
			Html::openElement( 'div', [ 'class' => "ext-discussiontools-emptystate mw-parser-output noarticletext" ] ) .
			Html::openElement( 'div', [ 'class' => "ext-discussiontools-emptystate-text" ] )
		);
		$titleMsg = false;
		$descMsg = false;
		$descParams = [];
		$buttonMsg = 'discussiontools-emptystate-button';
		$title = $context->getTitle();
		if ( $title->getNamespace() == NS_USER_TALK && !$title->isSubpage() ) {
			// This is a user talk page
			$isIP = $this->userNameUtils->isIP( $title->getText() );
			if ( $title->equals( $output->getUser()->getTalkPage() ) ) {
				// This is your own user talk page
				if ( $isIP ) {
					// You're an IP editor, so this is only *sort of* your talk page
					$titleMsg = 'discussiontools-emptystate-title-self-anon';
					$descMsg = 'discussiontools-emptystate-desc-self-anon';
					$query = $context->getRequest()->getValues();
					unset( $query['title'] );
					$descParams = [
						SpecialPage::getTitleFor( 'CreateAccount' )->getFullURL( [
							'returnto' => $context->getTitle()->getFullText(),
							'returntoquery' => wfArrayToCgi( $query ),
						] ),
						SpecialPage::getTitleFor( 'Userlogin' )->getFullURL( [
							'returnto' => $context->getTitle()->getFullText(),
							'returntoquery' => wfArrayToCgi( $query ),
						] ),
					];
				} else {
					// You're logged in, this is very much your talk page
					$titleMsg = 'discussiontools-emptystate-title-self';
					$descMsg = 'discussiontools-emptystate-desc-self';
				}
				$buttonMsg = false;
			} elseif ( $isIP ) {
				// This is an IP editor
				$titleMsg = 'discussiontools-emptystate-title-user-anon';
				$descMsg = 'discussiontools-emptystate-desc-user-anon';
			} else {
				// This is any other user
				$titleMsg = 'discussiontools-emptystate-title-user';
				$descMsg = 'discussiontools-emptystate-desc-user';
			}
		} else {
			// This is any other page on which DT is enabled
			$titleMsg = 'discussiontools-emptystate-title';
			$descMsg = 'discussiontools-emptystate-desc';
		}
		$output->addHTML( Html::rawElement( 'h3', [],
			$context->msg( $titleMsg )->parse()
		) );
		$output->addHTML( Html::rawElement( 'div', [ 'class' => 'plainlinks' ],
			$context->msg( $descMsg, $descParams )->parseAsBlock()
		) );
		if ( $buttonMsg ) {
			$output->addHTML( new ButtonWidget( [
				'label' => $context->msg( $buttonMsg )->text(),
				'href' => $title->getLocalURL( 'action=edit&section=new' ),
				'flags' => [ 'primary', 'progressive' ]
			] ) );
		}
		$output->addHTML(
			Html::closeElement( 'div' ) .
			Html::element( 'img', [
				'src' => $iconpath . '/emptystate.svg',
				'class' => "ext-discussiontools-emptystate-logo",
				// This is a purely decorative element
				'alt' => "",
			] ) .
			Html::closeElement( 'div' )
		);

		return false;
	}

	/**
	 * @param Article $article
	 * @param bool|ParserOutput &$outputDone
	 * @param bool &$pcache
	 * @return bool|void
	 */
	public function onArticleViewHeader( $article, &$outputDone, &$pcache ) {
		$title = $article->getTitle();
		$context = $article->getContext();
		$output = $context->getOutput();
		if (
			$output->getSkin()->getSkinName() === 'minerva' &&
			HookUtils::isFeatureEnabledForOutput( $output, HookUtils::NEWTOPICTOOL ) &&
			// Only add the button if "New section" tab would be shown in a normal skin.
			// Match the logic in MediaWiki core:
			// https://gerrit.wikimedia.org/r/plugins/gitiles/mediawiki/core/+/add6d0a0e38167a710fb47fac97ff3004451494c/includes/skins/SkinTemplate.php#1317
			(
				!HookUtils::hasPagePropCached( $title, 'nonewsectionlink' ) &&
				( $title->isTalkPage() || HookUtils::hasPagePropCached( $title, 'newsectionlink' ) )
			) &&
			// No need to show the button when the empty state banner is shown
			!HookUtils::shouldDisplayEmptyState( $context )
		) {
			// Minerva doesn't show a new topic button by default, unless the MobileFrontend
			// talk page feature is enabled, but we shouldn't depend on code from there.
			$output->enableOOUI();
			$output->addHTML(
				new ButtonWidget( [
					'href' => $title->getLinkURL( [ 'action' => 'edit', 'section' => 'new' ] ),
					// TODO: Make this a local message if the Minerva feature goes away
					'label' => $context->msg( 'minerva-talk-add-topic' )->text(),
					'flags' => [ 'progressive', 'primary' ],
					'classes' => [ 'ext-discussiontools-init-new-topic' ]
				] )
			);
		}
	}

	/**
	 * @param Title $title Title object for the page the edit notices are for
	 * @param int $oldid Revision ID that the edit notices are for (or 0 for latest)
	 * @param array &$notices Array of notices. Keys are i18n message keys, values are
	 *   parseAsBlock()ed messages.
	 * @return bool|void True or no return value to continue or false to abort
	 */
	public function onTitleGetEditNotices( $title, $oldid, &$notices ) {
		$context = RequestContext::getMain();

		if (
			// Hint is active
			$this->userOptionsLookup->getOption( $context->getUser(), 'discussiontools-newtopictool-hint-shown' ) &&
			// Turning off the new topic tool also dismisses the hint
			$this->userOptionsLookup->getOption( $context->getUser(), 'discussiontools-' . HookUtils::NEWTOPICTOOL ) &&
			// Only show when following the link from the new topic tool, never on normal edit attempts.
			// This can be called from within ApiVisualEditor, so we can't access most request parameters
			// for the main request. However, we can access 'editintro', because it's passed to the API.
			$context->getRequest()->getVal( 'editintro' ) === 'mw-dt-topic-hint'
		) {
			$context->getOutput()->enableOOUI();

			$returnUrl = $title->getFullURL( [
				'action' => 'edit',
				'section' => 'new',
				'dtenable' => '1',
			] );
			$prefUrl = SpecialPage::getTitleFor( 'Preferences' )
				->createFragmentTarget( 'mw-prefsection-editing-discussion' )->getFullURL();

			$topicHint = new MessageWidget( [
				'label' => new HtmlSnippet( wfMessage( 'discussiontools-newtopic-legacy-hint-return' )
					->params( $returnUrl, $prefUrl )->parse() ),
				'icon' => 'article',
				'classes' => [ 'ext-discussiontools-ui-newTopic-hint-return' ],
			] );

			// Add our notice above the built-in ones
			$notices = [
				'discussiontools-newtopic-legacy-hint-return' => (string)$topicHint,
			] + $notices;
		}
	}
}
