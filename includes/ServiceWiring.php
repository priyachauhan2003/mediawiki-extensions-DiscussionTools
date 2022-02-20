<?php

namespace MediaWiki\Extension\DiscussionTools;

use MediaWiki\MediaWikiServices;

return [
	'DiscussionTools.CommentParser' => static function ( MediaWikiServices $services ): CommentParser {
		return new CommentParser(
			$services->getContentLanguage(),
			$services->getMainConfig(),
			$services->getService( 'DiscussionTools.LanguageData' )
		);
	},
	'DiscussionTools.LanguageData' => static function ( MediaWikiServices $services ): LanguageData {
		return new LanguageData(
			$services->getMainConfig(),
			$services->getContentLanguage(),
			$services->getLanguageConverterFactory(),
			$services->getSpecialPageFactory()
		);
	},
	'DiscussionTools.SubscriptionStore' => static function ( MediaWikiServices $services ): SubscriptionStore {
		return new SubscriptionStore(
			$services->getConfigFactory(),
			$services->getDBLoadBalancerFactory(),
			$services->getReadOnlyMode(),
			$services->getUserFactory()
		);
	}
];
