<div class="klp-pickup-container">
    <h1>Container aanmelden voor ophalen</h1>
    <p>Voer uw unieke code in om uw container aan te melden voor ophalen. U vindt deze code in uw bestellingsbevestiging.</p>

    <?php if (isset($_GET['success']) && $_GET['success'] === '1') : ?>
        <div class="klp-pickup-success">
            <p><strong>Container succesvol aangemeld!</strong></p>
            <p>U ontvangt een bevestigingsmail. Wij nemen zo spoedig mogelijk contact met u op voor de exacte ophaaldatum.</p>
        </div>
    <?php elseif (isset($_GET['error'])) : ?>
        <div class="klp-pickup-error">
            <p><?= esc_html(stripslashes($_GET['error'])) ?></p>
        </div>
    <?php endif; ?>

    <div class="klp-pickup-form">
        <label for="klp_pickup_code">Uw ophaalcode</label>
        <input type="text" id="klp_pickup_code" name="code" value="<?= esc_attr($code) ?>" placeholder="Bijv. KL-1234-ABCD1234">
        <button type="button" id="klp_pickup_submit" class="button button-primary">Container aanmelden voor ophalen</button>
        <p class="klp-pickup-message" style="display:none;"></p>
    </div>
</div>
