<?php
/**
 * templates/product_list.php
 * Muestra un formulario para añadir/editar productos y una tabla con los existentes.
 */
?>

<!-- Título removido por solicitud -->

<div class="layout-container" style="display: flex; gap: 2rem; align-items: flex-start;">

    <div class="card form-card" style="flex: 1;">
        <h3>Añadir Nuevo Producto</h3>
        <form id="add-product-form">
            <div class="form-group">
                <label for="description">Descripción del Producto</label>
                <input type="text" id="description" name="description" class="form-control" placeholder="Ej: Diseño de logotipo" required>
            </div>
            <div class="form-group">
                <label for="price">Precio Base (sin IVA)</label>
                <input type="number" id="price" name="price" class="form-control" placeholder="Ej: 150.00" step="0.01" required>
            </div>
            <div class="form-group">
                <label for="vat">Tipo de IVA (%)</label>
                <select id="vat" name="vat" class="form-control" required>
                    <option value="21">21% (General)</option>
                    <option value="10">10% (Reducido)</option>
                    <option value="5">5% (Especial)</option>
                    <option value="4">4% (Superreducido)</option>
                    <option value="0">0% (Exento)</option>
                </select>
            </div>
            <input type="hidden" name="action" value="add_product">
            <button type="submit" class="btn btn-primary">Guardar Producto</button>
        </form>
    </div>

    <div class="card list-card" style="flex: 2;">
        <h3>Productos Existentes</h3>
        <?php
            // Paginación: 16 por página
            $perPage = 16;
            $pageParam = 'p';
            $currPage = max(1, (int)($_GET[$pageParam] ?? 1));
            $all = is_array($products) ? $products : (is_iterable($products) ? iterator_to_array($products) : []);
            $all = array_values($all);
            $total = count($all);
            $totalPages = max(1, (int)ceil($total / $perPage));
            if ($currPage > $totalPages) { $currPage = $totalPages; }
            $pageItems = array_slice($all, ($currPage - 1) * $perPage, $perPage);
        ?>
        <table>
            <thead>
                <tr>
                    <th>Descripción</th>
                    <th style="text-align: right;">Precio Base (€)</th>
                    <th style="text-align: center;">IVA (%)</th>
                    <th style="text-align: center;">Acciones</th>
                </tr>
            </thead>
            <tbody id="product-table-body">
                <?php if (empty($pageItems)): ?>
                    <tr>
                        <td colspan="4" style="text-align: center; padding: 2rem; color: var(--text-light);">
                            Aún no hay productos. Añade el primero usando el formulario.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($pageItems as $product): ?>
                        <tr>
                            <td><?php echo htmlspecialchars((string)$product->description); ?></td>
                            <td style="text-align: right;"><?php echo number_format((float)$product->price, 2, ',', '.'); ?></td>
                            <td style="text-align: center;"><?php echo htmlspecialchars((string)$product->vat); ?>%</td>
                            <td style="text-align: center;">
                                <a href="index.php?page=edit_product&id=<?php echo urlencode((string)$product->id); ?>" class="btn">Editar</a>
                                <button class="btn btn-danger delete-product-btn" data-id="<?php echo htmlspecialchars((string)$product->id); ?>">Borrar</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php if ($totalPages > 1): ?>
          <div class="pager" style="display:flex; gap:.5rem; align-items:center; flex-wrap:wrap; margin-top:.5rem;">
            <?php $q = $_GET; ?>
            <span>Mostrando <?= ($total===0?0:(($currPage-1)*$perPage+1)) ?>–<?= min($total, $currPage*$perPage) ?> de <?= $total ?></span>
            <span style="opacity:.6;">·</span>
            <?php if ($currPage > 1): ?>
              <?php $q[$pageParam] = $currPage - 1; ?>
              <a class="btn btn-sm" href="index.php?<?= htmlspecialchars(http_build_query($q)) ?>">« Anterior</a>
            <?php else: ?>
              <span class="btn btn-sm" style="opacity:.5; pointer-events:none;">« Anterior</span>
            <?php endif; ?>

            <?php
              $blocks = [];
              if ($totalPages <= 12) { $blocks[] = [1,$totalPages]; $useDots=false; }
              else { $blocks[] = [1,8]; $blocks[] = [$totalPages-3,$totalPages]; $useDots=true; }
              foreach ($blocks as $idx=>$range) {
                [$a,$b] = $range;
                if ($idx>0 && $useDots) echo '<span style="opacity:.6;">…</span>';
                for ($n=$a; $n<=$b; $n++) {
                  $q[$pageParam] = $n;
                  if ($n === $currPage) echo '<span class="btn btn-sm" style="pointer-events:none; font-weight:600;">'.(int)$n.'</span>';
                  else echo '<a class="btn btn-sm" href="index.php?'.htmlspecialchars(http_build_query($q)).'">'.(int)$n.'</a>';
                }
              }
            ?>

            <?php if ($currPage < $totalPages): ?>
              <?php $q[$pageParam] = $currPage + 1; ?>
              <a class="btn btn-sm" href="index.php?<?= htmlspecialchars(http_build_query($q)) ?>">Siguiente »</a>
            <?php else: ?>
              <span class="btn btn-sm" style="opacity:.5; pointer-events:none;">Siguiente »</span>
            <?php endif; ?>
            <span style="opacity:.6;">· Página <?= $currPage ?> de <?= $totalPages ?></span>
          </div>
        <?php endif; ?>
    </div>
</div>

<style>
/* Botones base celestes (consistentes con recibidas/enviadas) */
.btn{ display:inline-block; padding:.4rem .7rem; border-radius:.4rem; background:#e6f4ff; color:#0b74c4; text-decoration:none; border:1px solid #b3dbff; cursor:pointer; }
.btn:hover{ background:#dbeeff; }
</style>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // Script para añadir producto (sin cambios)
    const addProductForm = document.getElementById('add-product-form');
    if (addProductForm) {
        addProductForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(addProductForm);
            const response = await fetch('index.php', { method: 'POST', body: formData });
            const result = await response.json();
            if (result.success) {
                alert('Producto añadido con éxito.');
                location.reload();
            } else {
                alert('Error al añadir el producto: ' + (result.message || 'Causa desconocida.'));
            }
        });
    }

    // Script para borrar producto (mejorado para mostrar errores)
    const productTableBody = document.getElementById('product-table-body');
    productTableBody.addEventListener('click', async (e) => {
        if (e.target.classList.contains('delete-product-btn')) {
            const productId = e.target.dataset.id;
            if (confirm('¿Estás seguro de que quieres borrar este producto? Esta acción no se puede deshacer.')) {
                const formData = new FormData();
                formData.append('action', 'delete_product');
                formData.append('id', productId);

                try {
                    const response = await fetch('index.php', { method: 'POST', body: formData });
                    const result = await response.json();

                    if (result.success) {
                        alert('Producto borrado con éxito.');
                        location.reload();
                    } else {
                        // Muestra el mensaje de error específico del servidor
                        alert('Error al borrar el producto: ' + (result.message || 'Causa desconocida.'));
                    }
                } catch (error) {
                    alert('Ha ocurrido un error de comunicación.');
                }
            }
        }
    });
});
</script>
