<?php
// PDF template for received invoice: only the white box content
if (empty($rv) || empty($rv['success'])) { echo 'Factura recibida no disponible'; return; }

$h       = $rv['header'] ?? [];
$seller  = $rv['seller'] ?? [];
$buyer   = $rv['buyer'] ?? [];
$lines   = $rv['lines'] ?? [];
$totals  = $rv['totals'] ?? ['base'=>0,'vat'=>0,'irpf'=>0,'total'=>0];
$meta    = $rv['meta'] ?? [];

$rid = $h['id'] ?? ($meta['id'] ?? ($_GET['id'] ?? ''));

// Build QR: prefer provided PNG b64, else render from qrString
$qrString = (string)($meta['qrString'] ?? $meta['qr'] ?? '');
$qrImgB64 = (string)($meta['qrPngB64'] ?? '');
if ($qrImgB64 === '' && $qrString !== '') {
    try {
        $opts = new \chillerlan\QRCode\QROptions([
            'eccLevel'   => \chillerlan\QRCode\QRCode::ECC_M,
            'outputType' => \chillerlan\QRCode\QRCode::OUTPUT_IMAGE_PNG,
            'scale'      => 4,
        ]);
        $qrPng = (new \chillerlan\QRCode\QRCode($opts))->render($qrString);
        if (is_string($qrPng) && $qrPng !== '') {
            if (preg_match('~^data:image/[^;]+;base64,~i', $qrPng)) {
                $qrImgB64 = substr($qrPng, strpos($qrPng, ',') + 1) ?: '';
            } else {
                $qrImgB64 = base64_encode($qrPng);
            }
        }
    } catch (\Throwable $e) { $qrImgB64 = ''; }
}

$fmtMoney = fn($n) => number_format((float)$n, 2, ',', '.') . ' €';
?>
<!DOCTYPE html>
<html lang="es">
<meta charset="utf-8">
<style>
  @page { size: A4; margin: 15mm; }
  body { font-family: DejaVu Sans, Arial, sans-serif; color:#111; }
  .invoice { width:100%; }
  .header { display: table; width:100%; margin-bottom: 12px; }
  .col { display: table-cell; vertical-align: top; }
  .left { width:65%; }
  .right { width:35%; text-align:right; }
  .qr { height:120px; width:120px; object-fit: contain; }
  .box { border:1px solid #e5e7eb; padding:14px 16px; border-radius:8px; }
  .muted { color:#555; font-size: 12px; }
  h1 { font-size: 18px; margin: 0 0 6px 0; }
  h2 { font-size: 14px; margin: 0 0 6px 0; }
  .meta, .client { margin-bottom: 8px; }
  .grid { display: table; width:100%; }
  .grid > div { display: table-cell; vertical-align: top; width:50%; }
  table { width:100%; border-collapse: collapse; font-size: 12px; }
  th, td { padding: 6px 6px; border-bottom: 1px solid #eee; }
  th { background: #f8f9fb; text-align:left; }
  .num { text-align: right; white-space: nowrap; }
  .center { text-align: center; }
  .totals { margin-top: 10px; }
  .totals .row { display: table; width:100%; }
  .totals .row > div { display: table-cell; }
  .totals .row > div:first-child { width:70%; }
  .totals .row > div:last-child { width:30%; text-align:right; }
</style>
<body>
  <div class="invoice">
    <div class="header">
      <div class="col left">
        <h1>Factura recibida: <?= htmlspecialchars(($h['series'] ?? '').(($h['series'] ?? '') && ($h['number'] ?? '') ? '-' : '').($h['number'] ?? '')) ?></h1>
        <div class="box">
          <div><strong><?= htmlspecialchars($seller['name'] ?? '') ?></strong></div>
          <div>NIF: <?= htmlspecialchars($seller['nif'] ?? '') ?></div>
          <?php
            $addrPieces = array_filter([
              $seller['addr'] ?? '',
              trim(((string)($seller['pc'] ?? '')).' '.((string)($seller['town'] ?? ''))),
              $seller['prov'] ?? '',
            ]);
            $addrTxt = $addrPieces ? implode(', ', $addrPieces) : '';
          ?>
          <?php if ($addrTxt): ?><div><?= htmlspecialchars($addrTxt) ?></div><?php endif; ?>
        </div>
      </div>
      <div class="col right">
        <?php if ($qrImgB64 !== ''): ?>
          <img src="data:image/png;base64,<?= htmlspecialchars($qrImgB64) ?>" class="qr" alt="QR">
        <?php endif; ?>
      </div>
    </div>

    <div class="grid" style="margin-bottom:10px;">
      <div>
        <h2>Facturar a</h2>
        <div class="box">
          <div><strong><?= htmlspecialchars($buyer['name'] ?? '') ?></strong></div>
          <div>NIF: <?= htmlspecialchars($buyer['nif'] ?? '') ?></div>
          <?php
            $baddrPieces = array_filter([
              $buyer['addr'] ?? '',
              trim(((string)($buyer['pc'] ?? '')).' '.((string)($buyer['town'] ?? ''))),
              $buyer['prov'] ?? '',
            ]);
            $baddrTxt = $baddrPieces ? implode(', ', $baddrPieces) : '';
          ?>
          <?php if ($baddrTxt): ?><div><?= htmlspecialchars($baddrTxt) ?></div><?php endif; ?>
          <?php if (!empty($buyer['cc'])): ?><div>(<?= htmlspecialchars($buyer['cc']) ?>)</div><?php endif; ?>
        </div>
      </div>
      <div>
        <h2>Detalles</h2>
        <div class="box meta">
          <div><strong>Fecha:</strong> <span><?= htmlspecialchars($h['issueDate'] ?? '') ?></span></div>
          <?php if (!empty($h['concept'])): ?>
            <div><strong>Concepto:</strong> <span><?= htmlspecialchars($h['concept']) ?></span></div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <table>
      <thead>
        <tr>
          <th style="width:45%;">Descripción</th>
          <th class="center">Cant.</th>
          <th class="num">Precio Base</th>
          <th class="num">Base</th>
          <th class="center">IVA (%)</th>
          <th class="num">Total Línea</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($lines as $ln): ?>
        <tr>
          <td><?= htmlspecialchars((string)($ln['description'] ?? '')) ?></td>
          <td class="center"><?= htmlspecialchars((string)($ln['quantity'] ?? '')) ?></td>
          <td class="num"><?= $fmtMoney($ln['unitPrice'] ?? 0) ?></td>
          <td class="num"><?= $fmtMoney($ln['base'] ?? 0) ?></td>
          <td class="center"><?= htmlspecialchars((string)($ln['vatRate'] ?? 0)) ?>%</td>
          <td class="num"><?= $fmtMoney($ln['total'] ?? 0) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div class="totals">
      <div class="row"><div><strong>Base imponible</strong></div><div><?= $fmtMoney($totals['base'] ?? 0) ?></div></div>
      <div class="row"><div><strong>Total IVA</strong></div><div><?= $fmtMoney($totals['vat'] ?? 0) ?></div></div>
      <?php if (!empty($totals['irpf'])): ?>
      <div class="row"><div><strong>IRPF</strong></div><div><?= $fmtMoney($totals['irpf']) ?></div></div>
      <?php endif; ?>
      <div class="row" style="font-size:14px; font-weight:bold;"><div>Total</div><div><?= $fmtMoney($totals['total'] ?? 0) ?></div></div>
    </div>
  </div>
</body>
</html>

