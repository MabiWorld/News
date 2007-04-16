<?php
/**
 * News extension - shows recent changes on a wiki page.
 *
 * @package MediaWiki
 * @subpackage Extensions
 * @author Daniel Kinzler, brightbyte.de
 * @copyright © 2007 Daniel Kinzler
 * @licence GNU General Public Licence 2.0 or later
 */


if( !defined( 'MEDIAWIKI' ) ) {
	echo( "This file is an extension to the MediaWiki software and cannot be used standalone.\n" );
	die( 1 );
}

$wgExtensionCredits['other'][] = array( 
	'name' => 'News', 
	'author' => 'Daniel Kinzler, brightbyte.de', 
	'url' => 'http://mediawiki.org/wiki/Extension:News',
	'description' => 'shows customized recent changes on a wiki pages or as RSS or Atom feed',
);

$wgNewsFeedURLPattern = false; // pattern for feed-URLs; useful when using rewrites for canonical feed URLs
$wgNewsFeedUserPattern = false; // pattern to use for the author-field in feed items.

$wgExtensionFunctions[] = "wfNewsExtension";

$wgAutoloadClasses['NewsRenderer'] = dirname( __FILE__ ) . '/NewsRenderer.php';
$wgHooks['ArticleViewHeader'][] = 'wfNewsArticleViewHeader';
$wgHooks['ArticlePurge'][] = 'wfNewsArticlePurge';
$wgHooks['SkinTemplateOutputPageBeforeExec'][] = 'wfNewsSkinTemplateOutputPageBeforeExec';

//FIXME: find a way to override the feed URLs generated by OutputPage::getHeadLinks

function wfNewsExtension() {
    global $wgParser;
    $wgParser->setHook( "news", "wfNewsTag" );
    $wgParser->setHook( "newsfeed", "wfNewsFeedTag" );
    $wgParser->setHook( "newsfeedlink", "wfNewsFeedLinkTag" );
}

function wfNewsTag( $templatetext, $argv, &$parser ) {
    global $wgTitle;

    $parser->disableCache(); //TODO: use smart cache & purge...?
    $renderer = new NewsRenderer($wgTitle, $templatetext, $argv, $parser);

    return $renderer->renderNews();
}

function wfNewsFeedTag( $templatetext, $argv, &$parser ) {
    global $wgTitle, $wgOut;

    $parser->disableCache(); //TODO: use smart cache & purge...?
    $wgOut->setSyndicated( true );

    #$rss = $renderer->renderFeedMetaLink( 'rss' );
    #$atom = $renderer->renderFeedMetaLink( 'atom' );
    #$parser->mOutput->addHeadItem($rss . $atom);

    $renderer = new NewsRenderer($wgTitle, $templatetext, $argv, $parser);
    $html = $renderer->renderFeedPreview();
    return $html;
}

function wfNewsFeedLinkTag( $linktext, $argv, &$parser ) {
    return NewsRenderer::renderFeedLink($linktext, $argv, $parser);
}

function wfNewsCacheKey( $title, $format ) {
    //global $wgLang;
    //NOTE: per-language caching might be needed at some point.
    //      right now, caching is done for anon users only 
    //      (the content language might be set individually however, 
    //      using an extension like LanguageSelector)

    return "@newsfeed:" . urlencode($title->getPrefixedDBKey()) . '|' . urlencode($format);
}

function wfNewsArticleViewHeader( &$article ) {
    global $wgRequest, $wgOut, $wgFeedClasses, $wgUser;

    $format = $wgRequest->getVal( 'feed' );
    if (!$format) return true; 

    $wgOut->disable();
    //XXX: returning false currently doesn't stop the rest of Article::view to execute :(

    $title = $article->getTitle();
    $format = strtolower( trim($format) );

    if ( !isset($wgFeedClasses[$format] ) ) {
        wfHttpError(400, "Bad Request", "unknown feed format: " . $format); //TODO: better code & text
        return false;
    }

    if (!$article->exists()) {
        wfHttpError(404, "Not Found", "feed page not found: " . $title->getPrefixedText()); //TODO: better text
        return false;
    }

    $note = '';

    //NOTE: do caching for anon users only, because of user-specific 
    //      rendering of textual content
    if ($wgUser->isAnon()) {
        $cachekey = wfNewsCacheKey($title, $format);
        $ocache = wfGetParserCacheStorage();
        $e = $ocache ? $ocache->get( $cachekey ) : NULL;
        $note .= ' anon;';
    }
    else {
        $cachekey = NULL;
        $ocache = NULL;
        $e = NULL;
        $note .= ' user;';
    }

    if ( $e ) {
        $lastchange = wfTimestamp(TS_UNIX, NewsRenderer::getLastChangeTime());
        if ($lastchange < $e['timestamp']) {
            print $e['xml'] . "\n<!-- cached: $note -->\n";
            return false; //done
        }
        else {
            $note .= " stale: $lastchange >= {$e['timestamp']};";
        }
    }

    global $wgParser; //evil global

    if (!$wgParser->mOptions) { //XXX: ugly hack :(
        $wgParser->mOptions = new ParserOptions; 
        $wgParser->setOutputType( OT_HTML );
        $wgParser->clearState();
        $wgParser->mTitle = $title;
    }

    $renderer = NewsRenderer::newFromArticle( $article, $wgParser );
    if (!$renderer) {
        wfHttpError(404, "Bad Request", "no feed found on page: " . $title->getPrefixedText() ); //TODO: better code & text
        return;
    }

    $description = ''; //TODO: grab from article content... but what? and how?
    $ts = time();
    $xml = $renderer->renderFeed( $format, $description );

    $e = array( 'xml' => $xml, 'timestamp' => $ts );
    if ($ocache) {
        $ocache->set( $cachekey, $e, $ts + 24 * 60 * 60 ); //cache for max 24 hours; cached record is discarded when anything turns up in RC anyway.
        $note .= ' updated;';
    }

    $wgOut->disable();
    print $xml . "\n<!-- fresh: $note -->\n";
    return false; //done
}

function wfNewsArticlePurge( &$article ) {
    global $wgFeedClasses;

    $ocache = wfGetParserCacheStorage();
    if (!$ocache) return true;

    $title = $article->getTitle();

    foreach( $wgFeedClasses as $format => $class ) {
        $cachekey = wfNewsCacheKey( $title, $format );
        $ocache->delete( $cachekey );
    }

    return true;
}

function wfNewsSkinTemplateOutputPageBeforeExec( &$skin, &$tpl ) {
    $feeds = $tpl->data['feeds'];
    if (!$feeds) return true;

    $title = $skin->mTitle; //hack...

    foreach ($feeds as $format => $e) {
        $e['href'] = NewsRenderer::getFeedURL( $title, $format );
        $feeds[$format] = $e;
    }

    $tpl->setRef( 'feeds', $feeds );
    true;
}

?>