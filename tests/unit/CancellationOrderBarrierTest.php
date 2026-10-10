<?php
/** Cancellation intent survives late order callbacks and manual payment routes. */
use PHPUnit\Framework\TestCase;
use PHPUnit\Util\PHP\AbstractPhpProcess;
final class CancellationOrderBarrierTest extends TestCase {
 private function fixture():array{$path=dirname(__DIR__).'/fixtures/run-cancellation-order-barrier.php';$output=AbstractPhpProcess::factory()->runJob('<?php require '.var_export($path,true).';');self::assertSame('',$output['stderr']);$data=json_decode($output['stdout'],true);self::assertIsArray($data,$output['stdout']);return $data;}
 public function test_late_paid_pending_and_early_callbacks_do_not_change_cancelled_state_or_schedule():void{
  $data=$this->fixture();self::assertCount(18,$data['callbacks']);foreach($data['callbacks'] as $case){$label=implode('/',array($case['initial_status'],$case['type'],$case['order_status']));self::assertNull($case['error'],$label);self::assertSame($case['initial_status'],$case['status'],$label);self::assertSame($case['before'],$case['after'],$label);self::assertSame(array(),$case['effects'],$label);}
 }
 public function test_existing_renewal_and_early_order_payment_is_blocked():void{$data=$this->fixture();self::assertFalse($data['needs_payment']['renew']);self::assertFalse($data['needs_payment']['early-renew']);}
 public function test_manual_checkout_renewal_is_blocked_before_claim_or_order_writes():void{$data=$this->fixture();self::assertNull($data['manual']['error']);self::assertFalse($data['manual']['result']);self::assertSame(array(),$data['manual']['effects']);}
}
