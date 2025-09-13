<?php
// PDF template for emitted invoice: only the white box content
if (!$invoice) { echo 'Factura no encontrada'; return; }

// Load issuer config fallback
if (!isset($issuer) || !is_array($issuer)) { $issuer = []; }
$cfgPath = __DIR__ . '/../data/config.json';
$cfg = is_file($cfgPath) ? (json_decode((string)file_get_contents($cfgPath), true) ?: []) : [];
$cfgIssuer = $cfg['issuer'] ?? $cfg;
foreach (['companyName','nif','address','postCode','town','province','logoPath','email','iban'] as $k) {
    if (!isset($issuer[$k]) || $issuer[$k] === '' || $issuer[$k] === null) {
        if (isset($cfgIssuer[$k])) $issuer[$k] = $cfgIssuer[$k];
    }
}
$issuerEmail = $issuer['email'] ?? (string)($invoice->issuer->email ?? '');
$issuerIban  = $issuer['iban']  ?? (string)($invoice->issuer->iban  ?? '');
$issuerIban  = $issuerIban !== '' ? preg_replace('/\s+/', '', $issuerIban) : '';

$hasSuplidos = isset($invoice->suplidos) && isset($invoice->suplidos->suplido) && count($invoice->suplidos->suplido) > 0;
$totalIrpfAmount = (float)($invoice->totalIrpfAmount ?? 0);
$irpfRate = (float)($invoice->irpfRate ?? 0);

$baseDir = dirname(__DIR__);

$embedImg = function(?string $pathOrData) use ($baseDir): string {
    $s = (string)($pathOrData ?? '');
    if ($s === '') return '';
    if (preg_match('~^data:image/[^;]+;base64,~i', $s)) return $s;
    if (preg_match('~^[A-Za-z0-9+/\r\n]+={0,2}$~', $s) && (strlen($s) % 4) === 0) {
        return 'data:image/png;base64,' . $s;
    }
    // Try absolute path as-is first
    if (is_file($s)) {
        $abs = $s;
    } else {
        // Resolve relative to project root
        $rel = ($s !== '' && $s[0] === '/') ? $s : ('/' . ltrim($s, '/'));
        $abs = $baseDir . $rel;
    }
    if ($abs && is_file($abs)) {
        $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
        $mime = in_array($ext, ['jpg','jpeg']) ? 'image/jpeg' : (in_array($ext,['gif']) ? 'image/gif' : 'image/png');
        $b64  = base64_encode((string)file_get_contents($abs));
        return 'data:'.$mime.';base64,'.$b64;
    }
    return '';
};

// QR: pick best available source
$qrPath = (string)($invoice->verifactu->qrImagePath ?? $invoice->qrImagePath ?? '');
$qrB64  = (string)($invoice->verifactu->qrCodeB64 ?? $invoice->verifactu->qrCode ?? $invoice->qrCodeB64 ?? $invoice->qrCode ?? '');
$qrData = $embedImg($qrPath ?: $qrB64);
if ($qrData === '') {
    $qrText = (string)($invoice->verifactu->qrString ?? $invoice->qrString ?? '');
    if ($qrText !== '') {
        try {
            $opts = new \chillerlan\QRCode\QROptions([
                'eccLevel'   => \chillerlan\QRCode\QRCode::ECC_M,
                'outputType' => \chillerlan\QRCode\QRCode::OUTPUT_IMAGE_PNG,
                'scale'      => 4,
            ]);
            $qrPng = (new \chillerlan\QRCode\QRCode($opts))->render($qrText);
            if (is_string($qrPng) && $qrPng !== '') {
                $b64 = preg_match('~^data:image/[^;]+;base64,~i', $qrPng) ? substr($qrPng, strpos($qrPng, ',') + 1) : base64_encode($qrPng);
                $qrData = 'data:image/png;base64,' . $b64;
            }
        } catch (\Throwable $e) { /* ignore */ }
    }
}

// Logo
$logoData = '';
if (!empty($issuer['logoPath'])) {
    $logoData = $embedImg((string)$issuer['logoPath']);
}

// Simple formatter
$fmtMoney = fn($n) => number_format((float)$n, 2, ',', '.') . ' €';
// Hash actual (si existe)
$currHash = '';
if (isset($invoice->verifactu) && (string)$invoice->verifactu->hash !== '') {
    $currHash = (string)$invoice->verifactu->hash;
} elseif (isset($invoice->verifactuHash) && (string)$invoice->verifactuHash !== '') {
    $currHash = (string)$invoice->verifactuHash;
} elseif (isset($invoice->hash) && (string)$invoice->hash !== '') {
    $currHash = (string)$invoice->hash;
}

// Rectificada: mostrar aviso y por qué factura(s)
$isRectified = (string)($invoice->isCancelled ?? 'false') === 'true';
$rectIds = [];
if ($isRectified && isset($invoice->rectificativeId)) {
    foreach ($invoice->rectificativeId as $node) {
        $val = trim((string)$node);
        if ($val !== '') $rectIds[] = $val;
    }
    $rectIds = array_values(array_unique($rectIds));
}
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
  .logo { max-height:60px; }
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
  .supl { background:#fafafa; font-weight:bold; }
</style>
<body>
  <div class="invoice">
    <div class="header">
      <div class="col left">
        <?php if ($logoData): ?>
          <img src="<?= htmlspecialchars($logoData) ?>" class="logo" alt="Logo">
        <?php endif; ?>
        <h1>Factura: <?= htmlspecialchars((string)$invoice->id) ?></h1>
        <div class="box">
          <div><strong><?= htmlspecialchars($issuer['companyName'] ?? 'Tu Empresa') ?></strong></div>
          <div>NIF: <?= htmlspecialchars($issuer['nif'] ?? '') ?></div>
          <?php if (!empty($issuerEmail)): ?><div>Email: <?= htmlspecialchars($issuerEmail) ?></div><?php endif; ?>
          <?php if (!empty($issuerIban)): ?><div>IBAN: <?= htmlspecialchars($issuerIban) ?></div><?php endif; ?>
          <div><?= htmlspecialchars($issuer['address'] ?? '') ?></div>
          <div><?= htmlspecialchars(($issuer['postCode'] ?? '').' '.($issuer['town'] ?? '').', '.($issuer['province'] ?? '')) ?></div>
        </div>
      </div>
      <div class="col right">
        <?php if ($qrData): ?>
          <img src="<?= htmlspecialchars($qrData) ?>" class="qr" alt="QR">
        <?php endif; ?>
      </div>
    </div>

    <div class="grid" style="margin-bottom:10px;">
      <div>
        <h2>Facturar a</h2>
        <div class="box client">
          <div><strong><?= htmlspecialchars((string)$invoice->client->name) ?></strong></div>
          <div>NIF: <?= htmlspecialchars((string)$invoice->client->nif) ?></div>
          <div><?= htmlspecialchars((string)$invoice->client->address) ?></div>
          <div><?= htmlspecialchars((string)$invoice->client->postCode) ?> <?= htmlspecialchars((string)$invoice->client->town) ?>, <?= htmlspecialchars((string)$invoice->client->province) ?> (<?= htmlspecialchars((string)$invoice->client->countryCode) ?>)</div>
        </div>
      </div>
      <div>
        <h2>Detalles</h2>
        <div class="box meta">
          <div><strong>Fecha:</strong> <span><?= date('d/m/Y', strtotime((string)$invoice->issueDate)) ?></span></div>
          <?php if (!empty((string)$invoice->faceb2b->registrationCode)): ?>
          <div><strong>NºRegistro FACeB2B:</strong> <span><?= htmlspecialchars((string)$invoice->faceb2b->registrationCode) ?></span></div>
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
          <th class="num">Total Base</th>
          <th class="center">IVA (%)</th>
          <th class="num">Total Línea</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($invoice->items->item as $item): ?>
        <tr>
          <td><?= htmlspecialchars((string)$item->description) ?></td>
          <td class="center"><?= htmlspecialchars((string)$item->quantity) ?></td>
          <td class="num"><?= $fmtMoney($item->unitPrice) ?></td>
          <td class="num"><?= $fmtMoney($item->lineBaseTotal) ?></td>
          <td class="center"><?= htmlspecialchars((string)$item->vatRate) ?>%</td>
          <td class="num"><?= $fmtMoney($item->lineTotal) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if ($hasSuplidos): ?>
        <tr class="supl"><td colspan="6">SUPLIDOS</td></tr>
          <?php foreach ($invoice->suplidos->suplido as $suplido): ?>
          <tr>
            <td colspan="5"><?= htmlspecialchars((string)$suplido->description) ?></td>
            <td class="num"><?= $fmtMoney($suplido->amount) ?></td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>

    <div class="totals">
      <div class="row"><div><strong>Base imponible</strong></div><div><?= $fmtMoney($invoice->totalBase) ?></div></div>
      <div class="row"><div><strong>Total IVA</strong></div><div><?= $fmtMoney($invoice->totalVatAmount) ?></div></div>
      <?php if ($totalIrpfAmount != 0): ?>
      <div class="row"><div><strong>Retención IRPF (<?= number_format($irpfRate, 0) ?>%)</strong></div><div><?= $fmtMoney($totalIrpfAmount) ?></div></div>
      <?php endif; ?>
      <?php if ($hasSuplidos): ?>
      <div class="row"><div><strong>Total Suplidos</strong></div><div><?= $fmtMoney($invoice->totalSuplidos) ?></div></div>
      <?php endif; ?>
      <div class="row" style="font-size:14px; font-weight:bold;"><div>Total</div><div><?= $fmtMoney($invoice->totalAmount) ?></div></div>
    </div>

    <?php if ($isRectified): ?>
      <div class="box" style="margin-top:10px; background:#FFF8E1; border-color:#F7D07A;">
        <div><strong>Factura Rectificada</strong></div>
        <?php if (!empty($rectIds)): ?>
          <div>Rectificada por: <em><?= htmlspecialchars(implode(', ', $rectIds)) ?></em></div>
        <?php endif; ?>
        <?php if (!empty($invoice->cancellationReason)): ?>
          <div>Motivo: <em><?= htmlspecialchars((string)$invoice->cancellationReason) ?></em></div>
        <?php endif; ?>
      </div>
    <?php endif; ?>
    <?php if ($currHash !== ''): ?>
    <div class="box" style="margin-top:10px;">
      <div><strong>Hash actual:</strong></div>
      <div style="font-family: monospace; word-break: break-all; font-size: 11px;">
        <?= htmlspecialchars($currHash) ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</body>
</html>
