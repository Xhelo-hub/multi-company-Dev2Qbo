<?php
declare(strict_types=1); namespace App\Sync; use App\Http\QboClient; use App\Http\DevposClient; use App\Storage\MapStore; use App\Transformers\InvoiceTransformer; use App\Transformers\SalesReceiptTransformer;
class SalesSync{ public function __construct(private QboClient $qbo, private DevposClient $dev, private MapStore $maps) {}
  private function attachPdfIfAvailable(array $doc,string $etype,string $qboId): void{
    $eic=$doc['eic']??($doc['EIC']??null); $pdfB64=$doc['pdf']??null; if(!$pdfB64 && $eic){ try{$detail=$this->dev->getEInvoiceByEIC((string)$eic); $pdfB64=$detail['pdf']??null;}catch(\Throwable $e){} }
    if($pdfB64){ $binary=base64_decode($pdfB64); if($binary!==false && strlen($binary)>0){ $fn=($doc['documentNumber']??$doc['doc_no']??($etype.'_'.$qboId)).'.pdf'; try{$this->qbo->uploadAttachment($etype,$qboId,$fn,$binary,false);}catch(\Throwable $e){} } }
  }
  public function run(string $from,string $to): array{ $res=['invoices_created'=>0,'receipts_created'=>0,'skipped'=>0]; $fromIso=$from.'T00:00:00+02:00'; $toIso=$to.'T23:59:59+02:00';
    foreach($this->dev->fetchSalesEInvoices($fromIso,$toIso) as $doc){ $key=$doc['eic']??($doc['documentNumber']??$doc['id']??null); if(!$key){$res['skipped']++; continue;}
      if($this->maps->findDocument('devpos','sale',$key)){ $res['skipped']++; continue;} 
      // DEBUG: Log first invoice structure to see what DevPos sends
      static $logged=false; if(!$logged){ error_log("DEBUG DevPos Invoice: ".json_encode($doc,JSON_PRETTY_PRINT)); $logged=true; }
      $payload=InvoiceTransformer::fromDevpos($doc); $r=$this->qbo->createInvoice($payload);
      $id=(string)($r['Invoice']['Id']??$r['Invoice']['id']??''); if($id){ $this->attachPdfIfAvailable($doc,'Invoice',$id); $this->maps->mapDocument('devpos','sale',(string)$key,'Invoice',$id); $res['invoices_created']++; } }
    foreach($this->dev->fetchCashSales($fromIso,$toIso) as $doc){ $key=$doc['eic']??($doc['documentNumber']??$doc['id']??null); if(!$key){$res['skipped']++; continue;}
      if($this->maps->findDocument('devpos','cash',$key)){ $res['skipped']++; continue;} $payload=SalesReceiptTransformer::fromDevpos($doc); $r=$this->qbo->createSalesReceipt($payload);
      $id=(string)($r['SalesReceipt']['Id']??$r['SalesReceipt']['id']??''); if($id){ $this->attachPdfIfAvailable($doc,'SalesReceipt',$id); $this->maps->mapDocument('devpos','cash',(string)$key,'SalesReceipt',$id); $res['receipts_created']++; } }
    $this->maps->setCursor('sales_einvoice',$to.'T23:59:59'); $this->maps->setCursor('cash_sales',$to.'T23:59:59'); return $res; }
}
