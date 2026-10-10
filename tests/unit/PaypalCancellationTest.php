<?php
/** Provider cancellation confirmation must be based on a successful response. */
use PHPUnit\Framework\TestCase;
use PHPUnit\Util\PHP\AbstractPhpProcess;
final class PaypalCancellationTest extends TestCase {
 private function fixture():array{$path=dirname(__DIR__).'/fixtures/run-paypal-cancellation.php';$output=AbstractPhpProcess::factory()->runJob('<?php require '.var_export($path,true).';');self::assertSame('',$output['stderr']);$data=json_decode($output['stdout'],true);self::assertIsArray($data,$output['stdout']);return $data;}
 public function test_only_http_204_confirms_provider_cancellation():void{$data=$this->fixture();foreach(array('transport_error','timeout','http_400','http_500','http_200','http_202') as $name){self::assertFalse($data['responses'][$name],$name);}self::assertTrue($data['responses']['http_204']);}
 public function test_missing_parent_order_is_safe_and_makes_no_provider_request():void{$data=$this->fixture();self::assertNull($data['missing_order']['error']);self::assertSame(0,$data['missing_order']['requests']);}
 public function test_pending_cancellation_hook_stops_remote_future_billing_immediately():void{$data=$this->fixture();self::assertTrue($data['pending_hook']['registered']);self::assertNull($data['pending_hook']['error']);self::assertSame(1,$data['pending_hook']['cancel_requests']);}
 public function test_retry_reconciles_authoritative_already_cancelled_provider_after_ambiguous_response():void{$data=$this->fixture();foreach(array('legacy_cancelled','confirmation_write_repair','timeout_already_cancelled') as $name){$case=$data[$name];self::assertNull($case['error'],$name);self::assertGreaterThan(0,$case['get_requests'],$name.' must verify remote state rather than infer legacy metadata.');self::assertSame('paypal',$case['confirmed'],$name);}}
}
