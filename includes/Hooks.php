<?php
/**
 * DradabaTagCloud – Kategorie-Wortwolke für MediaWiki 1.42+
 *
 * Nutzung:
 *   <tagcloud />
 *   <tagcloud min="3" max="40" exclude="Wartung,Versteckte Kategorie" minsize="80" maxsize="220" />
 *   <tagcloud only="Drachen,Lenkdrachen,Einleiner" />
 *
 * Parameter:
 *   min      – Mindestanzahl Seiten in einer Kategorie (Standard: 1)
 *   max      – Maximale Anzahl angezeigter Kategorien (Standard: 0 = alle)
 *   exclude  – Kommagetrennte Liste auszuschließender Kategorien
 *   only     – Kommagetrennte Whitelist (nur diese Kategorien anzeigen)
 *   minsize  – Kleinste Schriftgröße in Prozent (Standard: 80)
 *   maxsize  – Größte Schriftgröße in Prozent (Standard: 200)
 *   refresh  – Cache-Dauer in Sekunden (Standard: 3600 = 1 Stunde)
 *
 * @license GPL-2.0-or-later
 */

namespace DradabaTagCloud;

use MediaWiki\MediaWikiServices;
use Parser;
use PPFrame;
use Title;

class Hooks {

	/**
	 * Parser-Hook registrieren.
	 */
	public static function onParserFirstCallInit( Parser $parser ): void {
		$parser->setHook( 'tagcloud', [ self::class, 'renderTagCloud' ] );
	}

	/**
	 * <tagcloud /> rendern.
	 *
	 * @param string|null $input  Inhalt zwischen den Tags (wird ignoriert)
	 * @param array        $args   Tag-Attribute
	 * @param Parser       $parser
	 * @param PPFrame      $frame
	 * @return string HTML
	 */
	public static function renderTagCloud(
		?string $input,
		array $args,
		Parser $parser,
		PPFrame $frame
	): string {
		// CSS-Modul laden
		$parser->getOutput()->addModuleStyles( [ 'ext.dradaba.tagcloud' ] );

		// --- Parameter parsen ---------------------------------------------------
		$min     = max( 1, intval( $args['min'] ?? 1 ) );
		$max     = max( 0, intval( $args['max'] ?? 0 ) );        // 0 = kein Limit
		$minSize = max( 50, intval( $args['minsize'] ?? 80 ) );
		$maxSize = max( $minSize, intval( $args['maxsize'] ?? 200 ) );
		$refresh = max( 0, intval( $args['refresh'] ?? 3600 ) );

		$exclude = self::parseList( $args['exclude'] ?? '' );
		$only    = self::parseList( $args['only'] ?? '' );

		// Cache-Dauer setzen (wegen Zufallssortierung sonst ewig gleich)
		if ( $refresh > 0 ) {
			$parser->getOutput()->updateCacheExpiry( $refresh );
		}

		// --- Datenbank-Abfrage --------------------------------------------------
		$categories = self::fetchCategories( $min, $max, $exclude, $only );

		if ( empty( $categories ) ) {
			return '<div class="dradaba-tagcloud dradaba-tagcloud-empty">'
				. wfMessage( 'dradaba-tagcloud-empty' )->escaped()
				. '</div>';
		}

		// --- Zufällig mischen ---------------------------------------------------
		shuffle( $categories );

		// --- HTML erzeugen ------------------------------------------------------
		return self::buildHtml( $categories, $minSize, $maxSize );
	}

	// =========================================================================
	// Private Hilfsmethoden
	// =========================================================================

	/**
	 * Kommagetrennte Liste parsen und normalisieren.
	 *
	 * @param string $value
	 * @return string[] Kategorienamen mit Unterstrichen statt Leerzeichen
	 */
	private static function parseList( string $value ): array {
		if ( $value === '' ) {
			return [];
		}

		return array_values( array_filter( array_map(
			static function ( string $item ): string {
				return str_replace( ' ', '_', trim( $item ) );
			},
			explode( ',', $value )
		) ) );
	}

	/**
	 * Kategorien mit Seitenanzahl aus der category-Tabelle holen.
	 *
	 * MediaWiki pflegt die Tabelle 'category' automatisch mit der Spalte
	 * 'cat_pages', die die Anzahl der Seiten pro Kategorie enthält.
	 * Das ist effizienter als categorylinks zu aggregieren.
	 *
	 * @param int      $min     Mindestanzahl Seiten
	 * @param int      $max     Maximale Ergebnisse (0 = unbegrenzt)
	 * @param string[] $exclude Auszuschließende Kategorienamen
	 * @param string[] $only    Whitelist (leer = alle)
	 * @return array<array{name: string, count: int}>
	 */
	private static function fetchCategories(
		int $min,
		int $max,
		array $exclude,
		array $only
	): array {
		$dbr = MediaWikiServices::getInstance()
			->getDBLoadBalancerFactory()
			->getReplicaDatabase();

		$conds = [];
		$conds[] = 'cat_pages >= ' . intval( $min );

		if ( !empty( $only ) ) {
			$conds['cat_title'] = $only;
		}

		if ( !empty( $exclude ) ) {
			$conds[] = 'cat_title NOT IN (' . $dbr->makeList( $exclude ) . ')';
		}

		$options = [ 'ORDER BY' => 'cat_pages DESC' ];
		if ( $max > 0 ) {
			$options['LIMIT'] = $max;
		}

		$res = $dbr->select(
			'category',
			[ 'cat_title', 'cat_pages' ],
			$conds,
			__METHOD__,
			$options
		);

		$categories = [];
		foreach ( $res as $row ) {
			$categories[] = [
				'name'  => $row->cat_title,
				'count' => intval( $row->cat_pages ),
			];
		}

		return $categories;
	}

	/**
	 * HTML für die Wortwolke erzeugen.
	 *
	 * @param array<array{name: string, count: int}> $categories
	 * @param int $minSize  Kleinste Schriftgröße (%)
	 * @param int $maxSize  Größte Schriftgröße (%)
	 * @return string
	 */
	private static function buildHtml( array $categories, int $minSize, int $maxSize ): string {
		$counts   = array_column( $categories, 'count' );
		$minCount = min( $counts );
		$maxCount = max( $counts );
		$range    = $maxCount - $minCount;

		$items = [];

		foreach ( $categories as $cat ) {
			// Schriftgröße berechnen
			$ratio    = ( $range > 0 )
				? ( $cat['count'] - $minCount ) / $range
				: 0.5;
			$fontSize = (int) round( $minSize + ( $maxSize - $minSize ) * $ratio );

			// MediaWiki-Title für korrekten Link
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
