<?php
/**
 * CSV parser for imports.
 *
 * @package StockDesk for WooCommerce
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SDFW_Import_Csv_Parser {

	/**
	 * Detect delimiter from CSV content.
	 *
	 * @param string $content CSV content.
	 * @return string Detected delimiter.
	 */
	public function detect_delimiter( string $content ): string {
		$first_line = explode( "\n", $content, 2 )[0] ?? '';
		$delimiters = array( ',', ';', "\t", '|' );
		$detected   = ',';
		$max_count  = 0;

		foreach ( $delimiters as $delimiter ) {
			$count = substr_count( $first_line, $delimiter );
			if ( $count > $max_count ) {
				$max_count = $count;
				$detected  = $delimiter;
			}
		}

		return $detected;
	}

	/**
	 * Parse CSV line respecting quotes.
	 *
	 * @param string $line      CSV line.
	 * @param string $delimiter Delimiter character.
	 * @return array<string> Parsed fields.
	 */
	public function parse_csv_line( string $line, string $delimiter ): array {
		$fields   = array();
		$field    = '';
		$in_quote = false;
		$length   = strlen( $line );

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $line[ $i ];

			if ( $char === '"' ) {
				if ( $in_quote && $i + 1 < $length && $line[ $i + 1 ] === '"' ) {
					$field .= '"';
					$i++;
				} else {
					$in_quote = ! $in_quote;
				}
				continue;
			}

			if ( ! $in_quote && $char === $delimiter ) {
				$fields[] = $field;
				$field    = '';
				continue;
			}

			$field .= $char;
		}

		$fields[] = $field;
		return $fields;
	}

	/**
	 * Parse CSV content into headers and rows.
	 *
	 * @param string $content CSV content.
	 * @return array{headers: array<string>, rows: array<array<string>>, delimiter: string}|WP_Error Parsed data or error.
	 */
	public function parse( string $content ) {
		$content = preg_replace( '/^\xEF\xBB\xBF/', '', $content );
		$content = trim( $content );

		if ( empty( $content ) ) {
			return new WP_Error( 'empty_csv', __( 'The uploaded CSV is empty.', 'stockdesk-for-woocommerce' ) );
		}

		$delimiter = $this->detect_delimiter( $content );
		$lines     = preg_split( '/\r\n|\r|\n/', $content );
		$lines     = array_filter( $lines, 'strlen' );

		if ( count( $lines ) < 2 ) {
			return new WP_Error( 'insufficient_data', __( 'The CSV needs a header row and at least one data row.', 'stockdesk-for-woocommerce' ) );
		}

		$headers = $this->parse_csv_line( array_shift( $lines ), $delimiter );
		$headers = array_map(
			function ( $header, $index ) {
				return $header ?: sprintf( /* translators: %d: Column number */ __( 'Column %d', 'stockdesk-for-woocommerce' ), $index + 1 );
			},
			$headers,
			array_keys( $headers )
		);

		$rows = array();
		foreach ( $lines as $line ) {
			$row = $this->parse_csv_line( $line, $delimiter );
			$row    = array_pad( $row, count( $headers ), '' );
			$row    = array_slice( $row, 0, count( $headers ) );
			$rows[] = $row;
		}

		return array(
			'headers'   => $headers,
			'rows'      => $rows,
			'delimiter' => $delimiter,
		);
	}
}
