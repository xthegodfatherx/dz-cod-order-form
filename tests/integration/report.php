<?php
/**
 * Prints PHPUnit (JUnit XML) failures and PHPCS (checkstyle) findings as GitHub Actions
 * annotations, so they are visible on the run page without opening the raw log.
 * Usage: php tests/integration/report.php <file.xml>
 *
 * @package DZCOD
 */

$file = isset( $argv[1] ) ? $argv[1] : '';
if ( ! is_readable( $file ) ) {
	echo "::error::No report file at {$file} — the step failed before writing it (bootstrap or install problem). Check the step log.\n";
	exit( 0 );
}
$xml = simplexml_load_file( $file );
$out = static function ( $level, $title, $text ) {
	$text = str_replace( array( '%', "\r", "\n" ), array( '%25', '%0D', '%0A' ), trim( (string) $text ) );
	echo "::{$level} title=" . str_replace( array( ',', ':' ), ' ', $title ) . '::' . substr( $text, 0, 3000 ) . "\n";
};
$count = 0;
// PHPUnit JUnit: <testcase><failure>/<error>.
foreach ( $xml->xpath( '//testcase' ) ?: array() as $case ) {
	foreach ( array( 'failure', 'error' ) as $kind ) {
		if ( isset( $case->{$kind} ) ) {
			$out( 'error', 'Test ' . $case['name'], $case->{$kind} );
			++$count;
		}
	}
}
// PHPCS checkstyle: <file name><error line message source>.
foreach ( $xml->xpath( '//file' ) ?: array() as $f ) {
	foreach ( $f->error as $e ) {
		$out( 'error' === (string) $e['severity'] ? 'error' : 'warning', basename( (string) $f['name'] ) . ' line ' . $e['line'], $e['message'] . ' (' . $e['source'] . ')' );
		++$count;
	}
}
if ( ! $count ) {
	echo "::error::The report has no failures listed — the problem happened outside the tests (see the step log).\n";
}
