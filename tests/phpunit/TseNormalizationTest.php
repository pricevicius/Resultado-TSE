<?php
class TseNormalizationTest extends WP_UnitTestCase {
	public function test_ea20_fixture_normalizes_totals_and_two_candidates(): void {
		$raw = json_decode( file_get_contents( dirname( __DIR__ ) . '/fixtures/ea20-minimal.json' ), true );
		$method = new ReflectionMethod( AE_TSE_Client::class, 'normalize_result' );
		$method->setAccessible( true );
		$result = $method->invoke( AE_TSE_Client::instance(), $raw, 'EA20' );
		$this->assertSame( 1000, $result['totals']['total_votes'] );
		$this->assertCount( 2, $result['candidates'] );
		$this->assertSame( 1, $result['candidates'][0]['elected'] );
	}
}
