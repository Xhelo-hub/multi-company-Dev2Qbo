<?php
declare(strict_types=1); namespace App\Sync; use App\Http\QboClient; use App\Http\DevposClient; use App\Storage\MapStore; use App\Transformers\InvoiceTransformer; use App\Transformers\SalesReceiptTransformer;
class SalesSync{ public function __construct(private QboClient $qbo, private DevposClient $dev, private MapStore $maps) {}
  private function attachPdfIfAvailable(array $doc, string $etype, string $qboId): void
  {
    $eic = $doc['eic'] ?? ($doc['EIC'] ?? null);
    $pdfB64 = $doc['pdf'] ?? null;
    
    // Log initial state
    error_log("DEBUG: attachPdfIfAvailable - Type: $etype, QBO ID: $qboId, EIC: " . ($eic ?? 'null') . ", Has PDF in doc: " . ($pdfB64 ? 'YES' : 'NO'));
    
    if (!$pdfB64 && $eic) {
      try {
        error_log("DEBUG: Fetching PDF from DevPos API for EIC: $eic");
        $detail = $this->dev->getEInvoiceByEIC((string)$eic);
        $pdfB64 = $detail['pdf'] ?? null;
        error_log("DEBUG: PDF retrieved from API: " . ($pdfB64 ? 'YES (' . strlen($pdfB64) . ' chars)' : 'NO'));
      } catch (\Throwable $e) {
        error_log("ERROR: Failed to fetch invoice detail for EIC $eic: " . $e->getMessage());
      }
    }
    
    if ($pdfB64) {
      $binary = base64_decode($pdfB64);
      if ($binary !== false && strlen($binary) > 0) {
        $fn = ($doc['documentNumber'] ?? $doc['doc_no'] ?? ($etype . '_' . $qboId)) . '.pdf';
        error_log("DEBUG: Attempting to upload attachment: $fn (" . strlen($binary) . " bytes)");
        try {
          $this->qbo->uploadAttachment($etype, $qboId, $fn, $binary, false);
          error_log("SUCCESS: Attachment uploaded successfully: $fn");
        } catch (\Throwable $e) {
          error_log("ERROR: Failed to upload attachment $fn: " . $e->getMessage());
        }
      } else {
        error_log("WARNING: PDF base64 decode failed or empty result");
      }
    } else {
      error_log("WARNING: No PDF found for $etype $qboId (EIC: " . ($eic ?? 'null') . ")");
    }
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
