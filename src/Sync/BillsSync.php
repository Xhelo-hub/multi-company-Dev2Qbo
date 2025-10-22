<?php

declare(strict_types=1);

namespace App\Sync;

use App\Http\QboClient;
use App\Http\DevposClient;
use App\Storage\MapStore;
use App\Transformers\BillTransformer;
use App\Helpers\CustomerVendorManager;

class BillsSync
{
  private CustomerVendorManager $cvMgr;
  public function __construct(private QboClient $qbo, private DevposClient $dev, private MapStore $maps)
  {
    $this->cvMgr = new CustomerVendorManager($qbo, $maps);
  }
  private function attachPdfIfAvailable(array $doc, string $etype, string $qboId): void
  {
    $eic = $doc['eic'] ?? ($doc['EIC'] ?? null);
    $pdfB64 = $doc['pdf'] ?? null;
    if (!$pdfB64 && $eic) {
      try {
        $detail = $this->dev->getEInvoiceByEIC((string)$eic);
        $pdfB64 = $detail['pdf'] ?? null;
      } catch (\Throwable $e) {
      }
    }
    if ($pdfB64) {
      $binary = base64_decode($pdfB64);
      if ($binary !== false && strlen($binary) > 0) {
        $fn = ($doc['documentNumber'] ?? $doc['doc_no'] ?? ('bill_' . $qboId)) . '.pdf';
        try {
          $this->qbo->uploadAttachment($etype, $qboId, $fn, $binary, false);
        } catch (\Throwable $e) {
        }
      }
    }
  }
  public function run(string $from, string $to): array
  {
    $res = ['bills_created' => 0, 'skipped' => 0];
    $fromIso = $from . 'T00:00:00+02:00';
    $toIso = $to . 'T23:59:59+02:00';
    foreach ($this->dev->fetchPurchaseEInvoices($fromIso, $toIso) as $doc) {
      $docNumber = $doc['documentNumber'] ?? $doc['id'] ?? null;
      $sellerNuis = $doc['sellerNuis'] ?? '';
      $amount = (float)($doc['amount'] ?? $doc['total'] ?? $doc['totalAmount'] ?? 0);

      if (!$docNumber) {
        $res['skipped']++;
        continue;
      }

      // Skip bills with zero or negative amounts (credit notes, returns, etc.)
      if ($amount <= 0) {
        $res['skipped']++;
        continue;
      }      // Check duplicate by document number + vendor NUIS combination
      $compositeKey = $docNumber . '|' . $sellerNuis;
      if ($this->maps->findDocument('devpos', 'purchase', $compositeKey)) {
        $res['skipped']++;
        continue;
      }

      // Get or create vendor before transforming
      $sellerName = $doc['sellerName'] ?? 'Unknown Vendor';
      $vendorId = $this->cvMgr->getOrCreateVendor($sellerNuis, $sellerName);
      if (!isset($doc['supplier'])) $doc['supplier'] = [];
      $doc['supplier']['qbo_id'] = $vendorId;
      $payload = BillTransformer::fromDevpos($doc);

      // Check if bill with same doc number already exists in QBO
      $originalDocNumber = $payload['DocNumber'];
      $existingBillWithSameNumber = null;
      try {
        $queryResult = $this->qbo->query("SELECT * FROM Bill WHERE DocNumber = '" . addslashes($originalDocNumber) . "'");
        if (!empty($queryResult['Bill'])) {
          $existingBillWithSameNumber = $queryResult['Bill'][0];
        }
      } catch (\Throwable $e) {
        // Query failed, will try to create anyway
      }

      // If bill with same number exists, check if it's same vendor
      if ($existingBillWithSameNumber) {
        $existingVendorId = (string)($existingBillWithSameNumber['VendorRef']['value'] ?? '');

        // Same vendor + same bill number = true duplicate, skip it
        if ($existingVendorId === $vendorId) {
          $res['skipped']++;
          continue;
        }

        // Different vendor but same bill number = add suffix
        $attempt = 1;
        while ($attempt < 10) {
          $payload['DocNumber'] = $originalDocNumber . '-' . $attempt;
          try {
            $checkResult = $this->qbo->query("SELECT * FROM Bill WHERE DocNumber = '" . addslashes($payload['DocNumber']) . "'");
            if (empty($checkResult['Bill'])) {
              break; // Found available number
            }
            $attempt++;
          } catch (\Throwable $e) {
            break; // Query failed, try this number
          }
        }
      }

      // Try to create bill
      try {
        $r = $this->qbo->createBill($payload);
        $id = (string)($r['Bill']['Id'] ?? $r['Bill']['id'] ?? '');
        if ($id) {
          $this->attachPdfIfAvailable($doc, 'Bill', $id);
          $this->maps->mapDocument('devpos', 'purchase', $compositeKey, 'Bill', $id);
          $res['bills_created']++;
        }
      } catch (\Throwable $e) {
        // Skip if duplicate or validation errors (vendor mapping issues)
        if (
          str_contains($e->getMessage(), 'Duplicate Document Number') ||
          str_contains($e->getMessage(), 'type of name assigned') ||
          str_contains($e->getMessage(), 'Invalid Number')
        ) {
          $res['skipped']++;
          continue;
        }
        throw $e; // Re-throw other errors
      }
    }
    $this->maps->setCursor('purchases_einvoice', $to . 'T23:59:59');
    return $res;
  }
}
