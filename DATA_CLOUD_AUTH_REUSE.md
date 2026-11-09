# ABH Data Cloud OAuth reuse — no-write certification

ABH has selected the three documented OAuth scopes (api, cdp_ingest_api,
refresh_token/offline_access) on the existing ABH PrestaShop Marketing Cloud Growth
External Client App. This permits a first no-write test without creating another
app or adding secrets. Salesforce still requires the Run As user to be entitled
to Data Cloud; selected scopes are not equivalent to successful authorization.

After administrator save, reload and persistence verification, manually run
Salesforce Data Cloud Token Exchange Only on main from GitHub Actions.

The workflow uses the protected salesforce-growth-certification Environment,
the same variables and secrets already used for certified Salesforce Core OAuth.
It requests a fresh Core token and exchanges it at /services/a360/token using
the pre-existing DataCloud/TokenExchangeClient. It records only sanitized status
and booleans. It DOES NOT log tokens or Data Cloud instance URLs, read or ingest
records, upload schemas, create streams or send campaigns.

The three scopes are listed by the Salesforce Data Cloud Ingestion API docs.
The client_credentials grant does not need to consume a refresh token, but
keeping offline_access selected is compatible with the published setup recipe.

A green result proves ONLY the token exchange. It does NOT approve uploading
the still-unapproved ABH_PST_SCHEMA_REVIEW.yaml, deploying Data Streams or
starting customer/order data transfer. The PST connector remains Schema Required.
If the run fails, investigate the sanitized code and administrator Run As
permissions, WITHOUT changing the already working Core OAuth secrets.

Documentation:
https://developer.salesforce.com/docs/data/data-cloud-int/guide/c360-a-create-ingestion-api-connected-app.html
https://developer.salesforce.com/docs/data/data-cloud-int/references/data-cloud-ingestionapi-ref/c360-a-api-get-started.html
