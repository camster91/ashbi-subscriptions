<?php
/** Independent real-source cancellation evidence acceptance regressions. */
use PHPUnit\Framework\TestCase;
use PHPUnit\Util\PHP\AbstractPhpProcess;

final class CancellationEvidenceTest extends TestCase {
 private function fixture(): array {
  $path=dirname(__DIR__).'/fixtures/run-cancellation-evidence.php';
  $result=AbstractPhpProcess::factory()->runJob('<?php require '.var_export($path,true).';');
  self::assertSame('',$result['stderr']);
  $data=json_decode($result['stdout'],true); self::assertIsArray($data,$result['stdout']);
  self::assertArrayNotHasKey('implementation_missing',$data,'CancellationEvidence implementation is required.'); return $data;
 }
 public function test_owner_cancel_persists_immutable_barrier_and_blocks_resurrection(): void {
  $d=$this->fixture(); self::assertContains($d['owner']['state'],array('pending','confirmed')); self::assertTrue($d['owner_blocked']);
  self::assertCount(1,$d['owner_barriers']); self::assertTrue($d['barrier_immutable']); self::assertTrue($d['resurrection_blocked']);
  self::assertSame('confirmed',$d['duplicate']['state']);
  foreach(array('hooks','status_writes','comments','status','meta') as $effect){self::assertSame($d['duplicate_before'][$effect],$d['duplicate_after'][$effect],'Duplicate must not repeat lifecycle effects: '.$effect);}
  self::assertGreaterThan($d['duplicate_before']['events'],$d['duplicate_after']['events'],'Repeated received requests preserve append-only evidence.');
 }
 public function test_authorization_is_enforced_independent_of_controller(): void {
  $d=$this->fixture(); foreach(array('foreign','guest','spoofed') as $name){self::assertSame('failed',$d[$name]['state'],$name);self::assertSame(0,$d[$name.'_barriers'],$name);}
  foreach(array('foreign','guest','spoofed') as $name){foreach($d[$name.'_events'] as $event){self::assertSame(0,(int)$event['subscription_id']);self::assertSame(0,(int)$event['actor_id']);self::assertSame('request_rejected',$event['event_type']);}}
  self::assertContains($d['admin']['state'],array('pending','confirmed')); self::assertSame(1,$d['admin_barriers']);
 }
 public function test_outcome_audit_failure_does_not_claim_complete_evidence(): void {
  $d=$this->fixture(); self::assertTrue($d['outcome_audit_write_blocked']);
  self::assertFalse($d['outcome_audit_write']['audit_complete'],'A failed outcome insert must not be reported as complete evidence.');
  self::assertNotSame('confirmed',$d['outcome_audit_write']['state']);
 }
 public function test_repeated_request_repairs_failed_writes_using_immutable_original_deadline(): void {
  $d=$this->fixture(); foreach(array('repair_status','repair_meta') as $name){
   $case=$d[$name]; self::assertTrue($case['first']['barrier']);self::assertSame('pending',$case['first']['state']);
   self::assertSame($case['original'],$case['final'],'Repair must not replace original authorized intent.');
   $end=(int)$case['original'][99]['access_end'];
   self::assertSame('confirmed',$case['second']['state'],$name);self::assertSame($end,(int)$case['second']['access_end']);
   self::assertSame($end,(int)$case['meta']['_subscrpt_cancel_at']);self::assertSame(0,(int)$case['meta']['_subscrpt_auto_renew']);
   self::assertSame('pe_cancelled',$case['status'],'An unexpired original access period must remain pending cancellation.');self::assertTrue($case['blocked']);
  }
 }
 public function test_missing_parent_order_cannot_confirm_unknown_billing_route(): void {
  $d=$this->fixture();self::assertTrue($d['missing_parent_blocked']);self::assertNotSame('confirmed',$d['missing_parent']['state']);
 }
 public function test_storage_and_lock_failures_never_confirm_cancellation(): void {
  $d=$this->fixture(); foreach(array('barrier_write','database_read','lock','status_write','meta_write') as $name){self::assertArrayNotHasKey('threw',$d[$name]);self::assertContains($d[$name]['state'],array('failed','pending'),$name);}
  self::assertTrue($d['database_read_blocked']); self::assertTrue($d['status_write_blocked']); self::assertTrue($d['meta_write_blocked']);
 }
 public function test_feedback_comment_mail_and_audit_failure_do_not_remove_barrier(): void {
  $d=$this->fixture(); foreach(array('comment_write','mailer','audit_write') as $name){self::assertArrayNotHasKey('threw',$d[$name]);self::assertTrue($d[$name.'_blocked']);}
  self::assertNotSame('confirmed',$d['audit_write']['state'],'Do not claim complete durable evidence when ledger insertion failed.');
 }
 public function test_append_only_events_allowlist_details_and_surface_write_failure(): void {
  $d=$this->fixture(); self::assertTrue($d['record_first']);self::assertTrue($d['record_second']);self::assertFalse($d['record_failure']);self::assertCount(2,$d['events']);
  foreach($d['events'] as $event){self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/',$event['request_id']);self::assertSame(99,(int)$event['subscription_id']);}
  $details=json_decode($d['events'][0]['details'],true);self::assertIsArray($details);
  self::assertEmpty(array_diff(array_keys($details),array('code','status','order_id','access_end','audit_complete','provider_state')));
  $serialized=json_encode($d['events']);foreach(array('fixture-secret','private@example.test','4242424242424242','fixture-token','nested-secret','unnecessary personal data') as $secret){self::assertStringNotContainsString($secret,$serialized);}
  foreach($d['mutations'] as $mutation){self::assertNotSame('forbidden',$mutation[0]);}
 }
}
