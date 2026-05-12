<div class="wrap">
    <h1>Leveringsplanner</h1>

    <?php if (isset($_GET['klp_gc_oauth']) && $_GET['klp_gc_oauth'] === 'success'): ?>
        <div class="notice notice-success is-dismissible"><p>Google Calendar succesvol gekoppeld!</p></div>
    <?php endif; ?>
    <?php settings_errors('klp_settings'); ?>

    <div class="klp-tabs">
        <nav class="klp-tab-nav">
            <a href="#klp-tab-settings" class="klp-tab-active">Instellingen</a>
            <a href="#klp-tab-calendar">Jaarkalender</a>
            <a href="#klp-tab-email">E-mail teksten</a>
            <a href="#klp-tab-google">Google Calendar</a>
        </nav>

        <div class="klp-tab-content">

            <!-- TAB: INSTELLINGEN -->
            <div id="klp-tab-settings" class="klp-tab-panel klp-panel-active">
                <form method="post" action="options.php">
                    <?php settings_fields(KLP_Settings::OPTION_KEY); ?>
                    <?php $s = KLP_Settings::get(); ?>

                    <div class="klp-section">
                        <h2>Tijdvakken</h2>
                        <table class="form-table">
                            <tr>
                                <th><label for="morning_label">Ochtend</label></th>
                                <td><input type="text" id="morning_label" name="klp_settings[morning_label]" value="<?= esc_attr($s['morning_label']) ?>" class="regular-text"></td>
                            </tr>
                            <tr>
                                <th><label for="afternoon_label">Middag</label></th>
                                <td><input type="text" id="afternoon_label" name="klp_settings[afternoon_label]" value="<?= esc_attr($s['afternoon_label']) ?>" class="regular-text"></td>
                            </tr>
                            <tr>
                                <th><label for="max_per_day">Max leveringen per dag</label></th>
                                <td><input type="number" id="max_per_day" name="klp_settings[max_per_day]" value="<?= esc_attr($s['max_per_day']) ?>" min="1" class="small-text"></td>
                            </tr>
                        </table>
                    </div>

                    <div class="klp-section">
                        <h2>Sluitingsdagen</h2>
                        <p class="description">Standaard Nederlandse feestdagen worden automatisch uitgesloten. Gebruik de <strong>Jaarkalender</strong> tab om dagen te selecteren.</p>
                        <input type="hidden" id="klp_closed_dates_field" name="klp_settings[closed_dates]" value="<?= esc_textarea($s['closed_dates'] ?? '') ?>">
                        <div class="klp-closed-dates-repeater">
                            <div id="klp_closed_dates_list"></div>
                        </div>
                    </div>

                    <div class="klp-section">
                        <h2>E-mail</h2>
                        <table class="form-table">
                            <tr>
                                <th><label for="pickup_email">Ophaal notificatie</label></th>
                                <td><input type="email" id="pickup_email" name="klp_settings[pickup_email]" value="<?= esc_attr($s['pickup_email']) ?>" class="regular-text"><p class="description">Ontvangt bericht bij ophaalaanmelding</p></td>
                            </tr>
                            <tr>
                                <th><label for="admin_email">Dagoverzicht</label></th>
                                <td><input type="email" id="admin_email" name="klp_settings[admin_email]" value="<?= esc_attr($s['admin_email']) ?>" class="regular-text"><p class="description">Ontvangt dagelijks om 07:00 een overzicht</p></td>
                            </tr>
                            <tr>
                                <th><label for="reminder_days_before">Herinnering (dagen voor levering)</label></th>
                                <td><input type="number" id="reminder_days_before" name="klp_settings[reminder_days_before]" value="<?= esc_attr($s['reminder_days_before']) ?>" min="0" class="small-text"></td>
                            </tr>
                            <tr>
                                <th><label for="pickup_reminder_days">Ophaalherinnering na (dagen)</label></th>
                                <td><input type="number" id="pickup_reminder_days" name="klp_settings[pickup_reminder_days]" value="<?= esc_attr($s['pickup_reminder_days']) ?>" min="1" class="small-text"><p class="description">Eerste herinnering als container niet is aangemeld</p></td>
                            </tr>
                            <tr>
                                <th><label for="escalated_reminder_days">Laatste herinnering na (dagen)</label></th>
                                <td><input type="number" id="escalated_reminder_days" name="klp_settings[escalated_reminder_days]" value="<?= esc_attr($s['escalated_reminder_days'] ?? 21) ?>" min="1" class="small-text"><p class="description">Tweede, dringende herinnering bij nog niet aangemeld</p></td>
                            </tr>
                        </table>
                    </div>

                    <?php submit_button('Instellingen opslaan'); ?>
                </form>
                <div class="klp-section" style="background:#f0f6fc;border-color:#72aee6;">
                    <p style="margin:0;font-size:12px;color:#555;">
                        <strong>E-mail templates</strong> vind je in:<br>
                        <code style="background:#fff;padding:2px 6px;border-radius:3px;font-size:12px;">wp-content/plugins/kolenbrander-leveringsplanner/templates/emails/</code><br>
                        <span style="color:#999;">Bewerk de <code>.php</code> bestanden om de e-mail inhoud en opmaak aan te passen.</span>
                    </p>
                </div>
            </div>

            <!-- TAB: JAARKALENDER -->
            <div id="klp-tab-calendar" class="klp-tab-panel">
                <div class="klp-section">
                    <h2>Jaarkalender</h2>
                    <p>Klik een maand in het jaaroverzicht om in te zoomen, of klik een datum aan om die als <strong>sluitingsdag</strong> te markeren.</p>
                    <div class="klp-legend">
                        <span class="klp-dot klp-dot-green"></span> Beschikbaar
                        <span class="klp-dot klp-dot-red"></span> Volgeboekt
                        <span class="klp-dot klp-dot-grey"></span> Gesloten
                        <span class="klp-dot klp-dot-orange"></span> Extra sluitingsdag
                    </div>
                    <div id="klp-admin-calendar">
                        <div id="klp-cal-nav"></div>
                        <div id="klp-cal-grid"></div>
                    </div>
                    <p id="klp-cal-feedback"></p>
                </div>
            </div>

            <!-- TAB: E-MAIL TEKSTEN -->
            <div id="klp-tab-email" class="klp-tab-panel">
                <div class="klp-section">
                    <h2>E-mail teksten</h2>
                    <p class="description">De e-mails worden automatisch verpakt in de standaard WooCommerce e-mail opmaak (met header, footer en opmaak).</p>
                    <p style="font-size:12px;color:#555;">
                        Beschikbare plaatshouders:<br>
                        <code>{customer_name}</code> <code>{order_number}</code> <code>{delivery_date}</code> <code>{time_slot}</code>
                        <code>{days_before}</code> <code>{pickup_url}</code> <code>{site_name}</code>
                    </p>
                    <form method="post" action="options.php">
                        <?php settings_fields(KLP_Settings::OPTION_KEY); ?>
                        <?php $s = KLP_Settings::get(); ?>
                        <table class="form-table">
                            <tr><th>Dagoverzicht (onderwerp)</th><td><input type="text" name="klp_settings[email_subject_summary]" value="<?= esc_attr($s['email_subject_summary']) ?>" class="regular-text"></td></tr>
                            <tr><th>Herinnering levering (onderwerp)</th><td><input type="text" name="klp_settings[email_subject_reminder]" value="<?= esc_attr($s['email_subject_reminder']) ?>" class="regular-text"></td></tr>
                            <tr><th>Herinnering levering (bericht)</th><td><textarea name="klp_settings[email_body_reminder]" rows="5" class="large-text"><?= esc_textarea($s['email_body_reminder']) ?></textarea></td></tr>
                            <tr><th>Morgen geleverd (onderwerp)</th><td><input type="text" name="klp_settings[email_subject_tomorrow]" value="<?= esc_attr($s['email_subject_tomorrow']) ?>" class="regular-text"></td></tr>
                            <tr><th>Morgen geleverd (bericht)</th><td><textarea name="klp_settings[email_body_tomorrow]" rows="5" class="large-text"><?= esc_textarea($s['email_body_tomorrow']) ?></textarea></td></tr>
                            <tr><th>Ophaalherinnering (onderwerp)</th><td><input type="text" name="klp_settings[email_subject_pickup]" value="<?= esc_attr($s['email_subject_pickup']) ?>" class="regular-text"></td></tr>
                            <tr><th>Ophaalherinnering (bericht)</th><td><textarea name="klp_settings[email_body_pickup]" rows="5" class="large-text"><?= esc_textarea($s['email_body_pickup']) ?></textarea></td></tr>
                            <tr><th>Laatste herinnering (onderwerp)</th><td><input type="text" name="klp_settings[email_subject_pickup_escalated]" value="<?= esc_attr($s['email_subject_pickup_escalated']) ?>" class="regular-text"></td></tr>
                            <tr><th>Laatste herinnering (bericht)</th><td><textarea name="klp_settings[email_body_pickup_escalated]" rows="5" class="large-text"><?= esc_textarea($s['email_body_pickup_escalated']) ?></textarea></td></tr>
                            <tr><th>Ophaalnotificatie admin (onderwerp)</th><td><input type="text" name="klp_settings[email_subject_pickup_notify_admin]" value="<?= esc_attr($s['email_subject_pickup_notify_admin']) ?>" class="regular-text"></td></tr>
                            <tr><th>Ophaalbevestiging klant (onderwerp)</th><td><input type="text" name="klp_settings[email_subject_pickup_notify_customer]" value="<?= esc_attr($s['email_subject_pickup_notify_customer']) ?>" class="regular-text"></td></tr>
                            <tr><th>Ophaalbevestiging klant (bericht)</th><td><textarea name="klp_settings[email_body_pickup_notify_customer]" rows="5" class="large-text"><?= esc_textarea($s['email_body_pickup_notify_customer']) ?></textarea></td></tr>
                        </table>
                        <?php submit_button('E-mail teksten opslaan'); ?>
                    </form>
                </div>

                <div class="klp-section">
                    <h2>Test e-mails versturen</h2>
                    <p class="description">Klik op een type om een test-e-mail te sturen naar <strong><?= esc_html($s['admin_email']) ?></strong>.</p>
                    <p>
                        <button type="button" class="button klp-test-email" data-type="summary">Test dagoverzicht</button>
                        <button type="button" class="button klp-test-email" data-type="reminder">Test leveringsherinnering</button>
                        <button type="button" class="button klp-test-email" data-type="pickup_reminder">Test ophaalherinnering</button>
                        <button type="button" class="button klp-test-email" data-type="pickup_escalated">Test laatste herinnering</button>
                        <button type="button" class="button klp-test-email" data-type="pickup_notify">Test ophaalnotificatie (admin)</button>
                    </p>
                    <p id="klp-test-email-result" style="display:none;padding:6px 10px;border-radius:3px;"></p>
                </div>
            </div>

            <!-- TAB: GOOGLE CALENDAR -->
            <div id="klp-tab-google" class="klp-tab-panel">
                <form method="post" action="options.php">
                    <?php settings_fields(KLP_Settings::OPTION_KEY); ?>
                    <?php $s = KLP_Settings::get(); ?>

                    <div class="klp-section">
                        <h2>Google Calendar</h2>

                        <?php if (!KLP_Google_Calendar::has_oauth()): ?>
                        <div class="notice notice-info inline" style="margin:0 0 16px 0;">
                            <p><strong>Zo koppel je in 5 stappen:</strong></p>
                            <ol style="margin-bottom:0">
                                <li><strong>Vind Client ID en Secret</strong> op <a href="https://console.cloud.google.com/apis/credentials" target="_blank">Google Cloud Console</a> onder <strong>OAuth 2.0 Client IDs</strong></li>
                                <li>Vul ze hieronder in en klik op <strong>Opslaan</strong></li>
                                <li>Voeg bij diezelfde client deze redirect URI toe:<br>
                                <code><?= esc_html(KLP_Google_Calendar::get_redirect_uri()) ?></code></li>
                                <li>Voeg <strong>patrick@toonkolenbrander.nl</strong> toe als Test User in het OAuth consent screen</li>
                                <li>Klik op <strong>Koppelen met Google</strong> en log in</li>
                            </ol>
                        </div>
                        <?php endif; ?>

                        <table class="form-table">
                            <tr>
                                <th><label for="gc_client_id">Client ID</label></th>
                                <td><input type="text" id="gc_client_id" name="klp_settings[gc_client_id]" value="<?= esc_attr($s['gc_client_id']) ?>" class="regular-text"></td>
                            </tr>
                            <tr>
                                <th><label for="gc_client_secret">Client Secret</label></th>
                                <td><input type="text" id="gc_client_secret" name="klp_settings[gc_client_secret]" value="<?= esc_attr($s['gc_client_secret']) ?>" class="regular-text"></td>
                            </tr>

                            <?php if (KLP_Google_Calendar::has_oauth()): ?>
                            <tr>
                                <th>Status</th>
                                <td>
                                    <span class="klp-status-ok">&#10003; Verbonden</span>
                                    <?php if (!empty($s['gc_calendars'])): ?>
                                    <select name="klp_settings[gc_calendar_id]" class="form-select">
                                        <?php foreach ($s['gc_calendars'] as $id => $name): ?>
                                        <option value="<?= esc_attr($id) ?>" <?= selected($s['gc_calendar_id'], $id) ?>><?= esc_html($name) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th></th>
                                <td>
                                    <button type="button" id="klp_test_gc" class="button">Test verbinding</button>
                                    <span id="klp_test_gc_result" style="margin-left:8px;"></span>
                                    &nbsp;
                                    <a href="<?= KLP_Google_Calendar::get_auth_url() ?>" class="button">Opnieuw koppelen</a>
                                    <a href="<?= admin_url('admin.php?page=klp-settings&klp_gc_disconnect=1') ?>" class="button klp-btn-danger" onclick="return confirm('Weet je zeker dat je de koppeling wilt verbreken?')">Ontkoppelen</a>
                                </td>
                            </tr>
                            <?php elseif (KLP_Google_Calendar::has_oauth_creds()): ?>
                            <tr>
                                <th></th>
                                <td><a href="<?= KLP_Google_Calendar::get_auth_url() ?>" class="button button-primary">Koppelen met Google</a></td>
                            </tr>
                            <?php endif; ?>
                        </table>

                        <p>
                            <label><input type="checkbox" name="klp_settings[gc_enabled]" value="yes" <?= checked($s['gc_enabled'], 'yes') ?>> Google Calendar sync inschakelen</label>
                        </p>
                    </div>

                    <?php submit_button('Google opslaan'); ?>
                </form>
            </div>

        </div>
    </div>
</div>

