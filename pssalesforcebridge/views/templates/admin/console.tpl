{*
  Salesforce Growth + Data Cloud — client-facing pre-beta BO.
  The module supplies only sanitized status flags and explicit form values.
  Customer/order records, OAuth tokens and ingestion payloads never enter Smarty.
*}
<style>
{literal}
.sfmc-console{--sfmc-ink:#172c44;--sfmc-muted:#54657a;--sfmc-blue:#205eb8;--sfmc-border:#dce5f0;--sfmc-bg:#f6f8fc;color:var(--sfmc-ink);max-width:1140px;margin:18px auto 36px;font-family:inherit;font-size:14px;line-height:1.55}
.sfmc-console *{box-sizing:border-box}
.sfmc-console h2,.sfmc-console h3,.sfmc-console p{margin:0}
.sfmc-console .sfmc-hero{background:#152e50;color:#fff;border-radius:15px;padding:27px 30px;display:flex;justify-content:space-between;align-items:center;gap:18px;flex-wrap:wrap}
.sfmc-console .sfmc-eyebrow{font-size:12px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#accbeb;margin-bottom:6px}
.sfmc-console .sfmc-hero h2{font-size:25px;line-height:1.2;color:#fff;font-weight:750;margin-bottom:8px}
.sfmc-console .sfmc-hero p{color:#d9e7f5;max-width:660px}
.sfmc-console .sfmc-version{display:inline-flex;align-items:center;padding:7px 12px;border:1px solid #7294bb;border-radius:99px;white-space:nowrap;color:#fff}
.sfmc-console .sfmc-cards{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin:16px 0 24px}
.sfmc-console .sfmc-stat{padding:17px 18px;background:#fff;border:1px solid var(--sfmc-border);border-radius:12px;min-height:102px}
.sfmc-console .sfmc-stat-label{font-size:12px;color:var(--sfmc-muted);display:block;margin-bottom:9px}
.sfmc-console .sfmc-stat strong{display:flex;align-items:center;gap:8px;font-size:14px}
.sfmc-console .sfmc-dot{width:8px;height:8px;border-radius:50%;display:inline-block;flex:none;background:#b68c26}
.sfmc-console .sfmc-dot.good{background:#1d9868}
.sfmc-console .sfmc-dot.neutral{background:#6c819a}
.sfmc-console .sfmc-panel{border-radius:14px;background:#fff;border:1px solid var(--sfmc-border);box-shadow:0 4px 18px rgba(18,48,77,.04);padding:24px 28px;margin-bottom:16px}
.sfmc-console .sfmc-panel-title{display:flex;align-items:flex-start;gap:13px;margin-bottom:20px}
.sfmc-console .sfmc-step{background:#e7f0fd;color:#1457aa;border-radius:10px;min-width:36px;height:36px;display:grid;place-items:center;font-weight:800}
.sfmc-console .sfmc-panel-title h3{font-size:19px;font-weight:750;color:var(--sfmc-ink);line-height:1.28}
.sfmc-console .sfmc-panel-title p{color:var(--sfmc-muted);margin-top:5px}
.sfmc-console .sfmc-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin:18px 0}
.sfmc-console label{display:block;font-size:13px;font-weight:700;color:#2d4057;margin-bottom:6px}
.sfmc-console input[type=text],.sfmc-console input[type=number],.sfmc-console input[type=password],.sfmc-console input[type=url]{display:block;width:100%;min-height:43px;border:1px solid #aebdd0;border-radius:8px;background:#fff;padding:9px 12px;color:#132d46;font:inherit;box-shadow:none}
.sfmc-console input:focus-visible,.sfmc-console button:focus-visible,.sfmc-console summary:focus-visible{outline:3px solid #86baff;outline-offset:3px}
.sfmc-console input[readonly]{background:var(--sfmc-bg);color:var(--sfmc-muted)}
.sfmc-console .sfmc-hint{font-size:12px;color:var(--sfmc-muted);margin:6px 0}
.sfmc-console .sfmc-btn{appearance:none;display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:42px;padding:10px 17px;font:inherit;font-weight:750;border-radius:8px;cursor:pointer;transition:background .15s ease;white-space:normal;text-align:center}
.sfmc-console .sfmc-btn-primary{background:#185bb8;border:1px solid #185bb8;color:#fff}
.sfmc-console .sfmc-btn-primary:hover{background:#10498f;color:#fff}
.sfmc-console .sfmc-btn-secondary{background:#fff;border:1px solid #2d639f;color:#174c88}
.sfmc-console .sfmc-btn-secondary:hover{background:#edf5ff}
.sfmc-console .sfmc-btn[disabled]{background:#eef2f7;border-color:#ccd6e2;color:#63758d;cursor:not-allowed}
.sfmc-console .sfmc-steps{display:flex;flex-wrap:wrap;gap:9px;margin-top:15px}
.sfmc-console .sfmc-tag{font-size:12px;padding:5px 11px;border-radius:99px;background:#f0f4fa;color:#34516e}
.sfmc-console .sfmc-note{border-left:3px solid #b77b1c;background:#fff7e8;color:#604711;padding:11px 14px;margin:16px 0;border-radius:0 8px 8px 0;font-size:13px}
.sfmc-console .sfmc-note.info{border-color:#2866ad;background:#eef5fd;color:#24496c}
.sfmc-console details{border:1px solid #d5e1ed;border-radius:10px;margin-top:19px;background:#f9fbff}
.sfmc-console details>summary{list-style:none;cursor:pointer;font-weight:750;padding:15px 17px;display:flex;align-items:center;justify-content:space-between;gap:10px;color:#1b4777}
.sfmc-console details>summary::-webkit-details-marker{display:none}
.sfmc-console details>summary::after{content:"+";font-size:20px;font-weight:500;line-height:1}
.sfmc-console details[open]>summary::after{content:"−"}
.sfmc-console .sfmc-details-inner{padding:0 18px 20px}
.sfmc-console .sfmc-buttons{display:flex;flex-wrap:wrap;gap:10px;margin-top:18px}
.sfmc-console .sfmc-subhead{font-size:14px;font-weight:750;margin:17px 0 6px}
.sfmc-console .sfmc-divider{border-top:1px solid var(--sfmc-border);margin:18px 0}
.sfmc-console .sfmc-settings-summary{display:flex;align-items:center;gap:10px}
.sfmc-console .sfmc-settings-summary small{font-weight:400;color:var(--sfmc-muted)}
.sfmc-console .sfmc-foot{color:var(--sfmc-muted);font-size:12px;line-height:1.5;margin:16px 0 0}
@media(max-width:760px){.sfmc-console{margin:10px 0}.sfmc-console .sfmc-hero{padding:22px}.sfmc-console .sfmc-hero h2{font-size:21px}.sfmc-console .sfmc-cards{grid-template-columns:1fr}.sfmc-console .sfmc-stat{min-height:auto}.sfmc-console .sfmc-panel{padding:20px 16px}.sfmc-console .sfmc-fields{grid-template-columns:1fr}.sfmc-console .sfmc-btn{width:100%}}
@media(prefers-reduced-motion:reduce){.sfmc-console .sfmc-btn{transition:none}}
{/literal}
</style>

<div class="sfmc-console" aria-label="{l s='Integración PrestaShop con Salesforce' mod='pssalesforcebridge'}">
    <header class="sfmc-hero">
        <div>
            <div class="sfmc-eyebrow">ALFABH · PrestaShop</div>
            <h2>Salesforce Growth + Data Cloud</h2>
            <p>{l s='Gestiona la conexión y comprueba el envío de un cliente y su pedido desde esta tienda, sin activar campañas ni sincronizaciones automáticas.' mod='pssalesforcebridge'}</p>
        </div>
        <span class="sfmc-version">v{$sfmc_version|escape:'html':'UTF-8'} · Pre-beta</span>
    </header>

    <section class="sfmc-cards" aria-label="{l s='Estado de la integración' mod='pssalesforcebridge'}">
        <div class="sfmc-stat">
            <span class="sfmc-stat-label">{l s='Modo de funcionamiento' mod='pssalesforcebridge'}</span>
            <strong><span class="sfmc-dot neutral" aria-hidden="true"></span>{l s='Pruebas controladas' mod='pssalesforcebridge'}</strong>
            <p class="sfmc-hint">{l s='Los envíos automáticos están desactivados.' mod='pssalesforcebridge'}</p>
        </div>
        <div class="sfmc-stat">
            <span class="sfmc-stat-label">{l s='Identidad de la tienda' mod='pssalesforcebridge'}</span>
            {if $sfmc_identity_ready}
                <strong><span class="sfmc-dot good" aria-hidden="true"></span>{l s='Configurada' mod='pssalesforcebridge'}</strong>
                {if $sfmc_source_ready}
                    <p class="sfmc-hint">{l s='Origen Data Cloud:' mod='pssalesforcebridge'} {$sfmc_source_name|escape:'html':'UTF-8'}</p>
                {else}
                    <p class="sfmc-hint">{l s='Falta vincular el origen Data Cloud para esta tienda.' mod='pssalesforcebridge'}</p>
                {/if}
            {else}
                <strong><span class="sfmc-dot" aria-hidden="true"></span>{l s='Pendiente de configurar' mod='pssalesforcebridge'}</strong>
                <p class="sfmc-hint">{l s='Abre Configuración de tienda.' mod='pssalesforcebridge'}</p>
            {/if}
        </div>
        <div class="sfmc-stat">
            <span class="sfmc-stat-label">{l s='Credenciales de Salesforce' mod='pssalesforcebridge'}</span>
            {if $sfmc_credentials_ready}
                <strong><span class="sfmc-dot good" aria-hidden="true"></span>{l s='Guardadas de forma cifrada' mod='pssalesforcebridge'}</strong>
                <p class="sfmc-hint">{l s='Prueba OAuth para comprobar la conexión. No se han enviado registros.' mod='pssalesforcebridge'}</p>
            {elseif $sfmc_credentials_locked}
                <strong><span class="sfmc-dot" aria-hidden="true"></span>{l s='Necesitan revisión' mod='pssalesforcebridge'}</strong>
                <p class="sfmc-hint">{l s='Los datos cifrados no se pueden leer en este servidor.' mod='pssalesforcebridge'}</p>
            {else}
                <strong><span class="sfmc-dot" aria-hidden="true"></span>{l s='Sin configurar' mod='pssalesforcebridge'}</strong>
                <p class="sfmc-hint">{l s='Configura OAuth en este mismo módulo.' mod='pssalesforcebridge'}</p>
            {/if}
        </div>
    </section>

    <section class="sfmc-panel" aria-labelledby="sfmc-oauth-title">
        <div class="sfmc-panel-title">
            <div class="sfmc-step" aria-hidden="true">1</div>
            <div>
                <h3 id="sfmc-oauth-title">{l s='Conexión con Salesforce' mod='pssalesforcebridge'}</h3>
                <p>{l s='Configura de forma segura la Connected App de Salesforce Growth. Las claves quedan cifradas para esta tienda y nunca se muestran después de guardarlas.' mod='pssalesforcebridge'}</p>
            </div>
        </div>
        {if $sfmc_credentials_admin_allowed}
            <details {if !$sfmc_credentials_ready}open{/if}>
                <summary>{if $sfmc_credentials_ready}{l s='Gestionar credenciales guardadas' mod='pssalesforcebridge'}{else}{l s='Configurar las credenciales' mod='pssalesforcebridge'}{/if}</summary>
                <div class="sfmc-details-inner">
                    <form method="post" action="{$sfmc_action|escape:'html':'UTF-8'}" autocomplete="off">
                        <input type="hidden" name="PS_GROWTH_OAUTH_CSRF" value="{$sfmc_credentials_csrf|escape:'html':'UTF-8'}" />
                        <div class="sfmc-fields">
                            <div>
                                <label for="sfmc-oauth-org">{l s='Salesforce Org URL' mod='pssalesforcebridge'} *</label>
                                <input type="url" id="sfmc-oauth-org" name="PS_GROWTH_OAUTH_ORG" placeholder="https://organizacion.my.salesforce.com" maxlength="253" required autocomplete="off" />
                                <p class="sfmc-hint">{l s='La URL de My Domain de Salesforce Core (HTTPS).' mod='pssalesforcebridge'}</p>
                            </div>
                            <div>
                                <label for="sfmc-oauth-id">{l s='Client ID' mod='pssalesforcebridge'} *</label>
                                <input type="text" id="sfmc-oauth-id" name="PS_GROWTH_OAUTH_CLIENT_ID" placeholder="{l s='Consumer Key' mod='pssalesforcebridge'}" maxlength="512" required autocomplete="off" spellcheck="false" />
                            </div>
                        </div>
                        <label for="sfmc-oauth-secret">{l s='Client Secret' mod='pssalesforcebridge'} *</label>
                        <input type="password" id="sfmc-oauth-secret" name="PS_GROWTH_OAUTH_CLIENT_SECRET" maxlength="4096" required autocomplete="new-password" spellcheck="false" />
                        <p class="sfmc-hint">{l s='El secreto se cifrará con una clave privada del servidor distinta de la base de datos. Los campos se vacían tras guardar. Para modificar la conexión, introduce los tres valores de nuevo.' mod='pssalesforcebridge'}</p>
                        <div class="sfmc-buttons">
                            <button type="submit" name="submitPsGrowthOauthSave" value="1" class="sfmc-btn sfmc-btn-primary">{l s='Guardar conexión cifrada' mod='pssalesforcebridge'}</button>
                        </div>
                    </form>
                </div>
            </details>
            <div class="sfmc-buttons">
                <form method="post" action="{$sfmc_action|escape:'html':'UTF-8'}">
                    <input type="hidden" name="PS_GROWTH_OAUTH_CSRF" value="{$sfmc_credentials_csrf|escape:'html':'UTF-8'}" />
                    <button type="submit" name="submitPsGrowthOauthTest" value="1" class="sfmc-btn sfmc-btn-secondary" {if !$sfmc_credentials_ready}disabled{/if}>{l s='Probar conexión OAuth + Data Cloud' mod='pssalesforcebridge'}</button>
                </form>
            </div>
            <p class="sfmc-hint">{l s='La prueba solicita únicamente tokens OAuth. No consulta ni envía datos de clientes, pedidos o campañas.' mod='pssalesforcebridge'}</p>
            {if $sfmc_credentials_ready}
                <details>
                    <summary>{l s='Revocar conexión de esta tienda' mod='pssalesforcebridge'}</summary>
                    <div class="sfmc-details-inner">
                        <div class="sfmc-note">{l s='Revocar desactiva esta conexión sin afectar otras tiendas ni borrar datos históricos. No se puede deshacer sin introducir otra vez las credenciales.' mod='pssalesforcebridge'}</div>
                        <form method="post" action="{$sfmc_action|escape:'html':'UTF-8'}" autocomplete="off">
                            <input type="hidden" name="PS_GROWTH_OAUTH_CSRF" value="{$sfmc_credentials_csrf|escape:'html':'UTF-8'}" />
                            <label for="sfmc-oauth-delete">{l s='Escribe ELIMINAR_CONEXION_SALESFORCE' mod='pssalesforcebridge'}</label>
                            <input id="sfmc-oauth-delete" type="text" name="PS_GROWTH_OAUTH_DELETE_CONFIRM" autocomplete="off" spellcheck="false" />
                            <div class="sfmc-buttons">
                                <button type="submit" name="submitPsGrowthOauthRemove" value="1" class="sfmc-btn sfmc-btn-secondary">{l s='Revocar esta conexión' mod='pssalesforcebridge'}</button>
                            </div>
                        </form>
                    </div>
                </details>
            {/if}
        {else}
            <div class="sfmc-note info" role="status">{l s='Solo el administrador principal de una tienda concreta puede gestionar las credenciales OAuth. Selecciona la tienda y accede con el perfil autorizado.' mod='pssalesforcebridge'}</div>
        {/if}
    </section>

    <section class="sfmc-panel" aria-labelledby="sfmc-uat-title">
        <div class="sfmc-panel-title">
            <div class="sfmc-step" aria-hidden="true">2</div>
            <div>
                <h3 id="sfmc-uat-title">{l s='Comprobar cliente y pedido' mod='pssalesforcebridge'}</h3>
                <p>{l s='Selecciona un cliente y un pedido suyo de esta misma tienda. Primero valida los datos; después, si está autorizado, realiza una sola prueba de envío.' mod='pssalesforcebridge'}</p>
            </div>
        </div>

        <div class="sfmc-steps" aria-label="{l s='Estado de las dos pruebas individuales' mod='pssalesforcebridge'}">
            <span class="sfmc-tag">{l s='Cliente:' mod='pssalesforcebridge'} {$sfmc_customer_status|escape:'html':'UTF-8'}</span>
            <span class="sfmc-tag">{l s='Pedido:' mod='pssalesforcebridge'} {$sfmc_order_status|escape:'html':'UTF-8'}</span>
        </div>

        <form method="post" action="{$sfmc_action|escape:'html':'UTF-8'}" autocomplete="off">
            <div class="sfmc-fields">
                <div>
                    <label for="sfmc-customer">{l s='ID del cliente' mod='pssalesforcebridge'} <span aria-hidden="true">*</span></label>
                    <input id="sfmc-customer" type="number" inputmode="numeric" min="1" step="1" name="PS_GROWTH_ONE_CUSTOMER_ID" value="{$sfmc_customer_input|escape:'html':'UTF-8'}" required />
                    <p class="sfmc-hint">{l s='Identificador del cliente en PrestaShop.' mod='pssalesforcebridge'}</p>
                </div>
                <div>
                    <label for="sfmc-order">{l s='ID del pedido' mod='pssalesforcebridge'} <span aria-hidden="true">*</span></label>
                    <input id="sfmc-order" type="number" inputmode="numeric" min="1" step="1" name="PS_GROWTH_ONE_ORDER_ID" value="{$sfmc_order_input|escape:'html':'UTF-8'}" required />
                    <p class="sfmc-hint">{l s='Debe pertenecer al cliente seleccionado.' mod='pssalesforcebridge'}</p>
                </div>
            </div>

            <button class="sfmc-btn sfmc-btn-primary" name="submitPsGrowthOnePreview" value="1" type="submit">
                {l s='1. Validar datos sin enviar' mod='pssalesforcebridge'}
            </button>
            <p class="sfmc-hint">{l s='Esta validación consulta únicamente datos locales y no contacta con Salesforce.' mod='pssalesforcebridge'}</p>

            {if $sfmc_g8_is_stage && !$sfmc_capture_armed && $sfmc_credentials_admin_allowed}
                <input type="hidden" name="PS_GROWTH_G8_CSRF" value="{$sfmc_credentials_csrf|escape:'html':'UTF-8'}" />
                <button class="sfmc-btn sfmc-btn-secondary" name="submitPsGrowthG8CaptureInspect" value="1" type="submit">
                    {l s='Comprobar captura G3 sin enviar' mod='pssalesforcebridge'}
                </button>
                <p class="sfmc-hint">{l s='Comprueba que este cliente y este pedido aparecen en G3 como eventos vigentes bloqueados y sin intentos. No consulta ni envía nada a Salesforce.' mod='pssalesforcebridge'}</p>
                {if $sfmc_g8_target_can_bind}
                    <button class="sfmc-btn sfmc-btn-secondary" name="submitPsGrowthG8TargetBind" value="1" type="submit">
                        {l s='Fijar este cliente y pedido para UAT G8' mod='pssalesforcebridge'}
                    </button>
                    <p class="sfmc-hint">{l s='Exige los dos eventos G3 verificados. Después G8 procesará únicamente estos registros; no entrarán otros clientes o pedidos pendientes.' mod='pssalesforcebridge'}</p>
                {/if}
            {/if}

            <details>
                <summary>{l s='2. Enviar prueba individual a Salesforce' mod='pssalesforcebridge'}</summary>
                <div class="sfmc-details-inner">
                    <div class="sfmc-note">
                        <strong>{l s='Atención: envío real de datos' mod='pssalesforcebridge'}</strong>
                        <p>{l s='Requiere autorización para tratar el registro seleccionado. Solo hay un intento por objeto y tienda: una respuesta HTTP 202 no confirma que aparezca en Data Cloud. No se permite repetir automáticamente.' mod='pssalesforcebridge'}</p>
                    </div>
                    {if !$sfmc_can_send}
                        <div class="sfmc-note info" role="status">{l s='Antes de enviar: configura identidad, origen Data Cloud de esta tienda y conexión cifrada; mantén el modo Test con captura desactivada.' mod='pssalesforcebridge'}</div>
                    {/if}

                    <label for="sfmc-approval">{l s='Autorización expresa para el envío individual' mod='pssalesforcebridge'}</label>
                    <input type="text" id="sfmc-approval" name="PS_GROWTH_ONE_APPROVAL" autocomplete="off" spellcheck="false" placeholder="I_APPROVE_ONE_REAL_DATA_CLOUD_RECORD" />
                    <p class="sfmc-hint">{l s='Escribe exactamente el texto indicado. No concede consentimiento para campañas.' mod='pssalesforcebridge'}</p>

                    <div class="sfmc-buttons">
                        <button type="submit" name="submitPsGrowthOneCustomerSend" value="1" class="sfmc-btn sfmc-btn-secondary" {if !$sfmc_can_send}disabled{/if}>
                            {l s='2. Enviar un cliente' mod='pssalesforcebridge'}
                        </button>
                    </div>

                    <div class="sfmc-divider"></div>
                    <p class="sfmc-subhead">{l s='Último paso: pedido asociado' mod='pssalesforcebridge'}</p>
                    <p class="sfmc-hint">{l s='Comprueba primero en Salesforce que el cliente seleccionado está en el DLO. Solo después continúa con el pedido.' mod='pssalesforcebridge'}</p>
                    <label for="sfmc-dlo-check">{l s='Confirmación de cliente en Data Cloud' mod='pssalesforcebridge'}</label>
                    <input type="text" id="sfmc-dlo-check" name="PS_GROWTH_DLO_CUSTOMER_CONFIRM" autocomplete="off" spellcheck="false" placeholder="CUSTOMER_DLO_CONFIRMED_IN_SALESFORCE" />
                    <div class="sfmc-buttons">
                        <button type="submit" name="submitPsGrowthOneOrderSend" value="1" class="sfmc-btn sfmc-btn-secondary" {if !$sfmc_can_send}disabled{/if}>
                            {l s='3. Enviar el pedido vinculado' mod='pssalesforcebridge'}
                        </button>
                    </div>
                </div>
            </details>
        </form>
    </section>

    <section class="sfmc-panel" aria-label="{l s='Configuración de la tienda' mod='pssalesforcebridge'}">
        <div class="sfmc-panel-title">
            <div class="sfmc-step" aria-hidden="true">3</div>
            <div>
                <h3>{l s='Configuración de la tienda' mod='pssalesforcebridge'}</h3>
                <p>{l s='La identidad queda vinculada a esta instalación; no se cambia sin una migración controlada.' mod='pssalesforcebridge'}</p>
            </div>
        </div>
        <details {if !$sfmc_identity_ready || !$sfmc_source_ready}open{/if}>
            <summary>
                <span class="sfmc-settings-summary">{l s='Ver configuración de identidad' mod='pssalesforcebridge'}</span>
            </summary>
            <div class="sfmc-details-inner">
                <form method="post" action="{$sfmc_action|escape:'html':'UTF-8'}" autocomplete="off">
                    <div class="sfmc-fields">
                        <div>
                            <label for="sfmc-merchant">{l s='Código de comercio' mod='pssalesforcebridge'}</label>
                            <input type="text" id="sfmc-merchant" name="PS_GROWTH_MERCHANT" value="{$sfmc_merchant|escape:'html':'UTF-8'}" required maxlength="32" {if $sfmc_identity_ready}readonly{/if} />
                        </div>
                        <div>
                            <label for="sfmc-namespace">{l s='Identificador único de instalación' mod='pssalesforcebridge'}</label>
                            <input type="text" id="sfmc-namespace" name="PS_GROWTH_NAMESPACE" value="{$sfmc_namespace|escape:'html':'UTF-8'}" required maxlength="32" {if $sfmc_identity_ready}readonly{/if} />
                        </div>
                    </div>
                    {if !$sfmc_source_ready}
                        <div class="sfmc-divider"></div>
                        <p class="sfmc-subhead">{l s='Origen de Data Cloud de esta tienda' mod='pssalesforcebridge'}</p>
                        <p class="sfmc-hint">{l s='Para BC (ABH/BCABH) se reutiliza automáticamente su fuente histórica: deja estos dos campos vacíos. Para otras tiendas como DOABH introduce el API Name de una fuente Salesforce ya existente y confirma el esquema PSCustomer/PSOrder; DO no puede utilizar el origen de BC.' mod='pssalesforcebridge'}</p>
                        <div class="sfmc-fields">
                            <div>
                                <label for="sfmc-source">{l s='Nombre API del origen de ingesta' mod='pssalesforcebridge'}</label>
                                <input type="text" id="sfmc-source" name="PS_GROWTH_SOURCE_API" maxlength="96" autocomplete="off" spellcheck="false" />
                            </div>
                            <div>
                                <label for="sfmc-source-attest">{l s='Confirmación del origen y esquema' mod='pssalesforcebridge'}</label>
                                <input type="text" id="sfmc-source-attest" name="PS_GROWTH_SOURCE_ATTEST" placeholder="{$sfmc_source_confirmation|escape:'html':'UTF-8'}" autocomplete="off" spellcheck="false" />
                                <p class="sfmc-hint">{l s='Esta declaración no comprueba Salesforce automáticamente. Debes validar que el origen y su esquema existen antes del primer envío.' mod='pssalesforcebridge'}</p>
                            </div>
                        </div>
                    {else}
                        <p class="sfmc-hint">{l s='Origen asociado (no editable):' mod='pssalesforcebridge'} {$sfmc_source_name|escape:'html':'UTF-8'}</p>
                    {/if}
                    {if !$sfmc_identity_ready || !$sfmc_source_ready}
                        <button type="submit" name="submitPsGrowthBridge" value="1" class="sfmc-btn sfmc-btn-secondary">{l s='Guardar configuración de la tienda' mod='pssalesforcebridge'}</button>
                    {else}
                        <p class="sfmc-hint">{l s='Identidad y origen guardados. No pueden cambiarse desde aquí para evitar mezclas de datos entre tiendas.' mod='pssalesforcebridge'}</p>
                    {/if}
                </form>
            </div>
        </details>
    </section>

    {if $sfmc_g8_is_stage}
        <section class="sfmc-panel" aria-labelledby="sfmc-g8-uat-title">
            <h3 id="sfmc-g8-uat-title">{l s='Sincronización automática G8 · Staging' mod='pssalesforcebridge'}</h3>
            <p class="sfmc-hint">{l s='Operación de pruebas para esta tienda: utiliza la misma outbox G3, valida cada contacto y consulta Data Cloud antes de realizar un único envío. No activa campañas, reintentos ni producción.' mod='pssalesforcebridge'}</p>
            {if $sfmc_capture_armed}
                <div class="sfmc-note info" role="status">
                    {l s='Captura local de staging activa: crea ahora un cliente y un pedido sintéticos. Después usa Detener captura local antes de ejecutar el cron G8.' mod='pssalesforcebridge'}
                </div>
            {elseif $sfmc_g8_capture_prepare_available && !$sfmc_g8_staging_approved}
                <details>
                    <summary>{l s='1. Preparar G3/G7 y activar captura para la UAT' mod='pssalesforcebridge'}</summary>
                    <p class="sfmc-hint">{l s='Solo en la tienda de pruebas. Requiere copia de seguridad verificada e instala o comprueba las tablas G3/G7 existentes; no envía datos a Salesforce.' mod='pssalesforcebridge'}</p>
                    <form method="post" action="{$sfmc_action|escape:'html':'UTF-8'}" autocomplete="off">
                        <label for="sfmc-g8-capture-phrase">{l s='Confirmación exacta para preparar captura' mod='pssalesforcebridge'}</label>
                        <input id="sfmc-g8-capture-phrase" type="text" name="PS_GROWTH_G8_CAPTURE_CONFIRM" placeholder="{$sfmc_g8_capture_prepare_phrase|escape:'html':'UTF-8'}" required autocomplete="off" />
                        <label>
                            <input type="checkbox" name="PS_GROWTH_G8_BACKUP_CONFIRM" value="{$sfmc_g8_backup_phrase|escape:'html':'UTF-8'}" required />
                            {l s='Confirmo que existe una copia de seguridad verificada de esta tienda de staging.' mod='pssalesforcebridge'}
                        </label>
                        <input type="hidden" name="PS_GROWTH_G8_CSRF" value="{$sfmc_credentials_csrf|escape:'html':'UTF-8'}" />
                        <button class="sfmc-btn sfmc-btn-secondary" type="submit" name="submitPsGrowthG8CapturePrepare" value="1">{l s='Preparar staging y activar captura' mod='pssalesforcebridge'}</button>
                    </form>
                </details>
            {/if}
            {if !$sfmc_capture_armed}
            {if $sfmc_g8_target_bound && $sfmc_credentials_admin_allowed}
                <section class="sfmc-note info" aria-labelledby="sfmc-g8-receipt-title">
                    <h4 id="sfmc-g8-receipt-title">{l s='Recuperación tras resultado G8 no confirmado' mod='pssalesforcebridge'}</h4>
                    <p>{l s='Si hubo un error después de ejecutar G8, no vuelvas a enviar. Primero consulta el estado de los recibos G3 y después la presencia del cliente en Data Cloud. Ambas acciones son de solo lectura.' mod='pssalesforcebridge'}</p>
                    <form method="post" action="{$sfmc_action|escape:'html':'UTF-8'}">
                        <input type="hidden" name="PS_GROWTH_G8_CSRF" value="{$sfmc_credentials_csrf|escape:'html':'UTF-8'}" />
                        <button type="submit" name="submitPsGrowthG8ReadG3" value="1" class="sfmc-btn sfmc-btn-secondary">{l s='1. Revisar recibos G3 sin enviar' mod='pssalesforcebridge'}</button>
                    </form>
                    <form method="post" action="{$sfmc_action|escape:'html':'UTF-8'}">
                        <input type="hidden" name="PS_GROWTH_G8_CSRF" value="{$sfmc_credentials_csrf|escape:'html':'UTF-8'}" />
                        <button type="submit" name="submitPsGrowthG8ReadDlo" value="1" class="sfmc-btn sfmc-btn-secondary">{l s='2. Comprobar cliente en Data Cloud (lectura)' mod='pssalesforcebridge'}</button>
                    </form>
                    <p class="sfmc-hint">{l s='HTTP 202 no certifica persistencia, y una ausencia en Data Cloud no prueba que un envío incierto nunca llegue. Estas comprobaciones no permiten reintentar.' mod='pssalesforcebridge'}</p>
                </section>
            {/if}
            {if $sfmc_g8_target_bound}
                <div class="sfmc-note info" role="status">{l s='Selección UAT G8 fijada a un cliente y pedido concretos. No se procesarán eventos históricos de otros registros.' mod='pssalesforcebridge'}</div>
                {if $sfmc_credentials_admin_allowed && !$sfmc_g8_staging_approved}
                    <form method="post" action="{$sfmc_action|escape:'html':'UTF-8'}">
                        <input type="hidden" name="PS_GROWTH_G8_CSRF" value="{$sfmc_credentials_csrf|escape:'html':'UTF-8'}" />
                        <button type="submit" class="sfmc-btn sfmc-btn-secondary" name="submitPsGrowthG8TargetRevoke" value="1">{l s='Retirar selección de UAT G8' mod='pssalesforcebridge'}</button>
                    </form>
                {/if}
            {else}
                <div class="sfmc-note info" role="status">{l s='Antes de autorizar la UAT, fija el cliente y pedido validados mediante el botón de la sección 2. No se enviarán registros hasta la autorización y la operación cron protegida.' mod='pssalesforcebridge'}</div>
            {/if}
            {if $sfmc_g8_staging_approved}
                <div class="sfmc-note info" role="status">{l s='UAT G8 autorizada para los dos registros seleccionados. Primero verifica elegibilidad local, luego envía solo el cliente y comprueba su DLO antes de enviar el pedido. No hay campañas ni reintentos.' mod='pssalesforcebridge'}</div>
                {if $sfmc_credentials_admin_allowed}
                    <form method="post" action="{$sfmc_action|escape:'html':'UTF-8'}">
                        <input type="hidden" name="PS_GROWTH_G8_CSRF" value="{$sfmc_credentials_csrf|escape:'html':'UTF-8'}" />
                        <button type="submit" class="sfmc-btn sfmc-btn-secondary" name="submitPsGrowthG8Preflight" value="1">{l s='1. Comprobar elegibilidad G8 sin enviar' mod='pssalesforcebridge'}</button>
                    </form>
                    <details>
                        <summary>{l s='2. Ejecutar UAT G8 del cliente (un único intento)' mod='pssalesforcebridge'}</summary>
                        <p class="sfmc-hint">{l s='Esta acción puede enviar el cliente seleccionado a Salesforce Data Cloud. Exige preflight local y confirmación escrita. HTTP 202 no significa entrega verificada; comprueba Data Cloud antes de enviar el pedido. No reintentes ante respuestas inciertas.' mod='pssalesforcebridge'}</p>
                        <form method="post" action="{$sfmc_action|escape:'html':'UTF-8'}" autocomplete="off">
                            <label for="sfmc-g8-execute-customer">{l s='Confirmación exacta de ejecución del cliente' mod='pssalesforcebridge'}</label>
                            <input type="text" id="sfmc-g8-execute-customer" name="PS_GROWTH_G8_RUN_CONFIRM" placeholder="EXECUTE_G8_CUSTOMER_ONCE" required autocomplete="off" />
                            <input type="hidden" name="PS_GROWTH_G8_CSRF" value="{$sfmc_credentials_csrf|escape:'html':'UTF-8'}" />
                            <button type="submit" class="sfmc-btn sfmc-btn-secondary" name="submitPsGrowthG8RunCustomer" value="1">{l s='Ejecutar G8: solo cliente' mod='pssalesforcebridge'}</button>
                        </form>
                    </details>
                    <details>
                        <summary>{l s='3. Ejecutar UAT G8 del pedido (tras verificar cliente en Data Cloud)' mod='pssalesforcebridge'}</summary>
                        <p class="sfmc-hint">{l s='No utilizar hasta confirmar que el cliente aparece en Data Cloud. El worker vuelve a exigir cliente padre presente, consentimiento local y reserva G3. No envía campañas.' mod='pssalesforcebridge'}</p>
                        <form method="post" action="{$sfmc_action|escape:'html':'UTF-8'}" autocomplete="off">
                            <label for="sfmc-g8-execute-order">{l s='Confirmación exacta de ejecución del pedido' mod='pssalesforcebridge'}</label>
                            <input type="text" id="sfmc-g8-execute-order" name="PS_GROWTH_G8_RUN_CONFIRM" placeholder="EXECUTE_G8_ORDER_ONCE" required autocomplete="off" />
                            <input type="hidden" name="PS_GROWTH_G8_CSRF" value="{$sfmc_credentials_csrf|escape:'html':'UTF-8'}" />
                            <button type="submit" class="sfmc-btn sfmc-btn-secondary" name="submitPsGrowthG8RunOrder" value="1">{l s='Ejecutar G8: solo pedido' mod='pssalesforcebridge'}</button>
                        </form>
                    </details>
                {/if}
                {if $sfmc_credentials_admin_allowed}
                    <form method="post" action="{$sfmc_action|escape:'html':'UTF-8'}">
                        <input type="hidden" name="PS_GROWTH_G8_CSRF" value="{$sfmc_credentials_csrf|escape:'html':'UTF-8'}" />
                        <button class="sfmc-btn sfmc-btn-secondary" type="submit" name="submitPsGrowthG8UatRevoke" value="1">{l s='Desactivar autorización G8 de staging' mod='pssalesforcebridge'}</button>
                    </form>
                {/if}
            {else}
                {if $sfmc_g8_staging_available}
                    <form method="post" action="{$sfmc_action|escape:'html':'UTF-8'}" autocomplete="off">
                        <label for="sfmc-g8-confirm">{l s='Confirmación explícita del responsable' mod='pssalesforcebridge'}</label>
                        <p class="sfmc-hint">{l s='Esta autorización permite únicamente la prueba de ingesta Data Cloud en staging. No activa ningún cron por sí sola.' mod='pssalesforcebridge'}</p>
                        <input type="text" id="sfmc-g8-confirm" name="PS_GROWTH_G8_CONFIRM" placeholder="{$sfmc_g8_staging_confirmation|escape:'html':'UTF-8'}" required autocomplete="off" />
                        <input type="hidden" name="PS_GROWTH_G8_CSRF" value="{$sfmc_credentials_csrf|escape:'html':'UTF-8'}" />
                        <button type="submit" class="sfmc-btn sfmc-btn-secondary" name="submitPsGrowthG8UatApprove" value="1">{l s='Autorizar únicamente la UAT G8' mod='pssalesforcebridge'}</button>
                    </form>
                {else}
                    <div class="sfmc-note info" role="status">{l s='Para preparar la UAT, selecciona una tienda concreta, configura su origen y OAuth, mantén Test y captura OFF y utiliza la política marketing_optin.' mod='pssalesforcebridge'}</div>
                {/if}
            {/if}
            {/if}
        </section>
    {/if}

    {if $sfmc_capture_armed}
        <section class="sfmc-panel" aria-labelledby="sfmc-capture-alert">
            <h3 id="sfmc-capture-alert">{l s='Captura local de staging activa' mod='pssalesforcebridge'}</h3>
            <p class="sfmc-note">{l s='Los eventos nuevos quedan registrados como bloqueados en G3. Cuando hayas creado el cliente y pedido sintéticos, detén la captura para poder verificar y ejecutar la UAT G8.' mod='pssalesforcebridge'}</p>
            <form method="post" action="{$sfmc_action|escape:'html':'UTF-8'}">
                <button type="submit" name="submitPsGrowthStagingCaptureStop" value="1" class="sfmc-btn sfmc-btn-secondary">{l s='Detener captura local' mod='pssalesforcebridge'}</button>
            </form>
        </section>
    {/if}

    <p class="sfmc-foot">{l s='Este panel no activa campañas de Marketing Cloud Growth, recuperación de carritos, ingesta masiva ni reintentos. Las herramientas de diagnóstico histórico siguen conservadas en el código, pero no forman parte de la interfaz del cliente.' mod='pssalesforcebridge'}</p>
</div>