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

	public function test_official_ea20_accepts_zero_start(): void {
		$result = $this->normalize_fixture( 'ea20-zero.json' );
		$this->assertSame( 0, $result['totals']['total_votes'] );
		$this->assertSame( 0.0, $result['totals']['reported_percentage'] );
		$this->assertSame( 'not_started', $result['totals']['progress'] );
		$this->assertCount( 2, $result['candidates'] );
		$this->assertSame( 0, $result['candidates'][0]['votes'] );
	}

	public function test_official_ea20_final_uses_explicit_elected_flag(): void {
		$result = $this->normalize_fixture( 'ea20-final.json' );
		$this->assertTrue( $result['totals']['final'] );
		$this->assertSame( 'final', $result['totals']['progress'] );
		$this->assertSame( 1, $result['candidates'][0]['elected'] );
		$this->assertSame( 1, $result['candidates'][1]['elected'] );
		$this->assertSame( 0, $result['candidates'][2]['elected'] );
		$this->assertSame( 'Não eleito', $result['candidates'][2]['situation'] );
	}

	private function normalize_fixture( string $name ): array {
		$raw = json_decode( file_get_contents( dirname( __DIR__ ) . '/fixtures/' . $name ), true );
		$method = new ReflectionMethod( AE_TSE_Client::class, 'normalize_result' );
		$method->setAccessible( true );
		return $method->invoke( AE_TSE_Client::instance(), $raw, 'EA20' );
	}
}
