<h2>Dashboard</h2>
<p style="color: var(--text-light); margin-top: -1rem; margin-bottom: 2rem;">Aquí tienes un resumen de la facturación de tu negocio.</p>

<div class="layout-container" style="display: flex; gap: 2rem; align-items: flex-start;">

    <div class="card" style="flex: 1;">
        <h3>Total Facturado</h3>
        <?php
        $foundInvoices = false;
        if (!empty($yearlyStats)) {
            foreach ($yearlyStats as $year => $stats) {
                if ($stats['count'] > 0) {
                    echo '<div class="stat-line"><span>Facturado en ' . $year . ':</span><strong>' . number_format($stats['total'], 2, ',', '.') . ' €</strong></div>';
                    $foundInvoices = true;
                }
            }
        }
        if (!$foundInvoices) { echo '<p class="empty-stats">Aún no hay datos de facturación.</p>'; }
        ?>
    </div>

    <div class="card" style="flex: 1;">
        <h3>Nº de Facturas</h3>
        <?php
        $foundInvoices = false;
        if (!empty($yearlyStats)) {
            foreach ($yearlyStats as $year => $stats) {
                 if ($stats['count'] > 0) {
                    echo '<div class="stat-line"><span>Facturas en ' . $year . ':</span><strong>' . $stats['count'] . '</strong></div>';
                    $foundInvoices = true;
                 }
            }
        }
        if (!$foundInvoices) { echo '<p class="empty-stats">Aún no hay facturas emitidas.</p>'; }
        ?>
    </div>
    
    <div class="card" style="flex: 1;">
        <h3>Clientes</h3>
         <div class="stat-line">
            <span>Clientes registrados:</span>
            <strong><?php echo $clientCount; ?></strong>
        </div>
    </div>
</div>

<div class="card" style="margin-top: 2rem;">
    <h3>Últimas Facturas Emitidas (Global)</h3>
    <?php if (empty($recentInvoices)): ?>
        <p style="text-align: center; color: var(--text-light); padding: 2rem;">
            Aún no has emitido ninguna factura. <a href="index.php?page=create_invoice">¡Crea la primera ahora!</a>
        </p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>ID Factura</th>
                    <th>Cliente</th>
                    <th>Fecha de Emisión</th>
                    <th style="text-align: right;">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentInvoices as $invoice): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars((string)$invoice->id); ?></strong></td>
                        <td><?php echo htmlspecialchars((string)$invoice->client->name); ?> (<?php echo htmlspecialchars((string)$invoice->client->nif); ?>)</td>
                        <td><?php echo date('d/m/Y', strtotime((string)$invoice->issueDate)); ?></td>
                        <td style="text-align: right;"><?php echo number_format((float)$invoice->totalAmount, 2, ',', '.'); ?> €</td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<style>
    .stat-line {
        display: flex;
        justify-content: space-between;
        padding: 0.75rem 0;
        border-bottom: 1px solid var(--secondary-color);
        font-size: 0.9rem;
    }
    .stat-line:last-child {
        border-bottom: none;
    }
    .stat-line span {
        color: var(--text-light);
    }
    .empty-stats {
        color: var(--text-light);
        text-align: center;
        padding-top: 1rem;
    }
</style>
