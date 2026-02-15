<?php
/**
 * DradabaTagCloud – Kategorie-Wortwolke für MediaWiki 1.42+
 *
 * @license GPL-2.0-or-later
 */

namespace DradabaTagCloud;

use MediaWiki\MediaWikiServices;
use Parser;
use PPFrame;
use MediaWiki\Title\Title;

class Hooks {

	public static function onParserFirstCallInit( Parser $parser ): void {
		$parser->setHook( 'tagcloud', [ self::class, 'renderTagCloud' ] );
	}

	public static function renderTagCloud(
		?string $input,
		array $args,
		Parser $parser,
		PPFrame $frame
	): string {
		$parser->getOutput()->addModuleStyles( [ 'ext.dradaba.tagcloud' ] );

		$min     = max( 1, intval( $args['min'] ?? 1 ) );
		$max     = max( 0, intval( $args['max'] ?? 0 ) );
		$minSize = max( 50, intval( $args['minsize'] ?? 80 ) );
		$maxSize = max( $minSize, intval( $args['maxsize'] ?? 200 ) );
		$refresh = max( 0, intval( $args['refresh'] ?? 3600 ) );

		$exclude = self::parseList( $args['exclude'] ?? '' );
		$only    = self::parseList( $args['only'] ?? '' );

		if ( $refresh > 0 ) {
			$parser->getOutput()->updateCacheExpiry( $refresh );
		}

		$categories = self::fetchCategories( $min, $max, $exclude, $only );

		if ( empty( $categories ) ) {
			return '<div class="dradaba-tagcloud dradaba-tagcloud-empty">'
				. wfMessage( 'dradaba-tagcloud-empty' )->escaped()
				. '</div>';
		}

		shuffle( $categories );

		return self::buildHtml( $categories, $minSize, $maxSize );
	}

	private static function parseList( string $value ): array {
		if ( $value === '' ) {
			return [];
		}
		return array_values( array_filter( array_map(
			static fn( string $item ): string => str_replace( ' ', '_', trim( $item ) ),
			explode( ',', $value )
		) ) );
	}

	private static function fetchCategories(
		int $min,
		int $max,
		array $exclude,
		array $only
	): array {
		$services = MediaWikiServices::getInstance();

		// DB-Verbindung: MW 1.44+ vs. ältere Versionen
		if ( method_exists( $services, 'getConnectionProvider' ) ) {
			$dbr = $services->getConnectionProvider()->getReplicaDatabase();
		} else {
			$dbr = $services->getDBLoadBalancer()->getConnection( DB_REPLICA );
		}

		// Einfache Query: nur category-Tabelle, kein JOIN, kein GROUP BY
		$conditions = [ 'cat_pages >= ' . intval( $min ) ];

		// Whitelist direkt in der Query (sicher über Array-Syntax)
		if ( !empty( $only ) ) {
			$conditions['cat_title'] = $only;
		}

		$queryBuilder = $dbr->newSelectQueryBuilder()
			->select( [ 'cat_title', 'cat_pages' ] )
			->from( 'category' )
			->where( $conditions )
			->orderBy( 'cat_pages', 'DESC' )
			->caller( __METHOD__ );

		$res = $queryBuilder->fetchResultSet();

		// Ergebnisse sammeln, Exclude-Filter in PHP (einfacher + kompatibel)
		$excludeMap = array_flip( $exclude );
		$categories = [];

		foreach ( $res as $row ) {
			if ( isset( $excludeMap[$row->cat_title] ) ) {
				continue;
			}
			$categories[] = [
				'name'  => $row->cat_title,
				'count' => intval( $row->cat_pages ),
			];
		}

		// Limit anwenden (Daten sind bereits nach cat_pages DESC sortiert)
		if ( $max > 0 && count( $categories ) > $max ) {
			$categories = array_slice( $categories, 0, $max );
		}

		return $categories;
	}

	private static function buildHtml( array $categories, int $minSize, int $maxSize ): string {
		$counts   = array_column( $categories, 'count' );
		$minCount = min( $counts );
		$maxCount = max( $counts );
		$range    = $maxCount - $minCount;

		$items = [];

		foreach ( $categories as $cat ) {
			$ratio    = ( $range > 0 )
				? ( $cat['count'] - $minCount ) / $range
				: 0.5;
			$fontSize = (int) round( $minSize + ( $maxSize - $minSize ) * $ratio );

			$title = Title::makeTitleSafe( NS_CATEGORY, $cat['name'] );
			if ( $title === null ) {
				continue;
			}

			$displayName = str_replace( '_', ' ', $cat['name'] );
			$url         = $title->getLocalURL();
			$tooltip     = wfMessage( 'dradaba-tagcloud-tooltip', $displayName, $cat['count'] )
				->numParams( $cat['count'] )
				->text();

			$items[] = '<a href="' . htmlspecialchars( $url ) . '"'
				. ' class="dradaba-tagcloud-item"'
				. ' style="font-size:' . $fontSize . '%"'
				. ' title="' . htmlspecialchars( $tooltip ) . '">'
				. htmlspecialchars( $displayName )
				. '</a>';
		}

		return '<div class="dradaba-tagcloud">' . implode( "\n", $items ) . '</div>';
	}
}
