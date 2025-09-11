<?php
/**
 * templates/edit_product.php
 * Formulario para editar un producto existente.
 */

if (!$product) {
    echo "<h2>Error</h2><p>El producto no ha sido encontrado.</p>";
    return;
}

$vat_rates = [21, 10, 5, 4, 0];
?>

<h2>Editar Producto: <?php echo htmlspecialchars((string)$product->description); ?></h2>

<div class="card">
    <form id="edit-product-form">
        <input type="hidden" name="id" value="<?php echo htmlspecialchars((string)$product->id); ?>">
        <input type="hidden" name="action" value="update_product">

        <div class="form-group">
            <label for="description">Descripción del Producto</label>
            <input type="text" id="description" name="description" class="form-control" value="<?php echo htmlspecialchars((string)$product->description); ?>" required>
        </div>
        <div class="form-group">
            <label for="price">Precio Base (sin IVA)</label>
            <input type="number" id="price" name="price" class="form-control" value="<?php echo htmlspecialchars((string)$product->price); ?>" step="0.01" required>
        </div>
        <div class="form-group">
            <label for="vat">Tipo de IVA (%)</label>
            <select id="vat" name="vat" class="form-control" required>
                <?php foreach ($vat_rates as $rate): ?>
                    <option value="<?php echo $rate; ?>" <?php echo ((int)$product->vat == $rate) ? 'selected' : ''; ?>>
                        <?php echo $rate; ?>%
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div style="text-align: right; margin-top: 2rem;">
            <button type="submit" class="btn btn-primary">Guardar Cambios</button>
            <a href="index.php?page=products" class="btn" style="background-color: var(--text-light); color: white;">Cancelar</a>
        </div>
    </form>
</div>

<script>
document.getElementById('edit-product-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const formData = new FormData(e.target);
    const response = await fetch('index.php', { method: 'POST', body: formData });
    const result = await response.json();
    if (result.success) {
        alert('Producto actualizado con éxito.');
        window.location.href = 'index.php?page=products';
    } else {
        alert('Error al actualizar el producto.');
    }
});
</script>
