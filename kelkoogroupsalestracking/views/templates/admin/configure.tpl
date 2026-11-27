{**
 * Kelkoo Group Sales Tracking - official PrestaShop module.
 *
 * @author    Kelkoo Group
 * @copyright Kelkoo Group
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 *}
<form action="{$kst_action_url|escape:'htmlall':'UTF-8'}" method="post">
    <h3>{l s='Kelkoo Group Sales Tracking settings' mod='kelkoogroupsalestracking'}</h3>
    <p>{l s='Kelkoo Group Sales Tracking requires a few configuration steps. You received your Merchant Identifier by email at subscription - if you no longer have it, ask your Kelkoo Group account manager.' mod='kelkoogroupsalestracking'}</p>
    <p>{l s='Add one row per Kelkoo Group campaign running on this shop - most shops only need one.' mod='kelkoogroupsalestracking'}</p>

    <table class="table" id="kst-campaigns-table">
        <thead>
            <tr>
                <th>{l s='Country' mod='kelkoogroupsalestracking'}</th>
                <th>{l s='Merchant Identifier' mod='kelkoogroupsalestracking'}</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            {foreach from=$kst_campaigns item=campaign}
                <tr>
                    <td><input type="text" class="form-control" name="kst_country[]" value="{$campaign.country|escape:'htmlall':'UTF-8'}"></td>
                    <td><input type="text" class="form-control" name="kst_merchant_id[]" value="{$campaign.merchantId|escape:'htmlall':'UTF-8'}"></td>
                    <td><button type="button" class="btn btn-default kst-remove-campaign">&times;</button></td>
                </tr>
            {/foreach}
        </tbody>
    </table>
    <button type="button" id="kst-add-campaign" class="btn btn-default">{l s='+ Add campaign' mod='kelkoogroupsalestracking'}</button>

    <p class="help-block">{l s='Country: the 2-letter code of the country your products are listed on Kelkoo for (fr, uk, de, es, it, nl, nb for Flemish Belgium, be, at, ch, dk, fi, no, se, pl, pt, cz, mx, us...).' mod='kelkoogroupsalestracking'}</p>
    <p class="help-block">{l s='Merchant Identifier: the unique ID representing your shop within the Kelkoo Group system (also called comId).' mod='kelkoogroupsalestracking'}</p>

    <h4>{l s='Consent' mod='kelkoogroupsalestracking'}</h4>
    <p>{l s='The lead tag (https://s.kk-resources.com/leadtag.js, loaded on every page) must only run once the visitor has given consent: declare it as an advertising script in your consent management platform (CMP).' mod='kelkoogroupsalestracking'}</p>
    <p>{l s='Do not block the conversion tag (https://s.kk-resources.com/ks.js, order confirmation page) or the whole s.kk-resources.com domain: a blocked conversion tag means under-reported sales.' mod='kelkoogroupsalestracking'}</p>

    <button type="submit" name="submitKelkoogroupSalesTracking" class="btn btn-default pull-right">{l s='Save' mod='kelkoogroupsalestracking'}</button>
</form>
{* The JS only ever appends an EMPTY row, so it never escapes merchant input itself. *}
<script type="text/javascript">
{literal}
    (function () {
        var tbody = document.querySelector("#kst-campaigns-table tbody");
        var addButton = document.getElementById("kst-add-campaign");
        if (!tbody || !addButton) {
            return;
        }
        addButton.addEventListener("click", function () {
            var tr = document.createElement("tr");
            tr.innerHTML =
                "<td><input type=\"text\" class=\"form-control\" name=\"kst_country[]\"></td>" +
                "<td><input type=\"text\" class=\"form-control\" name=\"kst_merchant_id[]\"></td>" +
                "<td><button type=\"button\" class=\"btn btn-default kst-remove-campaign\">&times;</button></td>";
            tbody.appendChild(tr);
        });
        tbody.addEventListener("click", function (event) {
            if (event.target.classList.contains("kst-remove-campaign")) {
                event.target.closest("tr").remove();
            }
        });
    })();
{/literal}
</script>
