<?php
/**
 * Runs every unit test file in its own PHP process (each defines its own WordPress stubs).
 * Usage: php tests/unit/run.php   — exits 1 if any check fails.
 */
$failed = 0;
$passed = 0;
foreach ( glob( __DIR__ . '/test-*.php' ) as $file ) {
	$out  = array();
	$code = 0;
	exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $file ) . ' 2>&1', $out, $code );
	$fails  = preg_grep( '/^FAIL|Fatal error|Warning:|Notice:|Deprecated:/', $out );
	$passed += count( preg_grep( '/^PASS/', $out ) );
	if ( $code || $fails ) {
		$failed += max( 1, count( $fails ) );
		echo basename( $file ) . ":\n  " . implode( "\n  ", $fails ? $fails : $out ) . "\n";
	}
}
echo "\n{$passed} passed, {$failed} failed\n";
exit( $failed ? 1 : 0 );
