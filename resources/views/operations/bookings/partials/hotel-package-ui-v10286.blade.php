@php($erp10286IsGroupPackage = \App\Services\Operations\GroupPackageOperationalPolicy::isGroupPackage($booking ?? null))

@if($erp10286IsGroupPackage)
<div id="erp10286-hotel-policy"
     style="display:none;padding:8px 10px;border-radius:7px;background:#eaf8ef;color:#28764d;font-size:10px;font-weight:700;margin:8px 0;">
    Included in Package • Accommodation / voucher details only. Hotel sale, supplier cost, discount, commission and margin stay with the Group Package and are not entered here.
</div>

<script>
(function(){
    const policy = document.getElementById('erp10286-hotel-policy');
    if (!policy) return;

    const norm = t => (t || '').replace(/\s+/g,' ').trim().toLowerCase();
    const commercialTerms = [
        'customer price','customer sale','customer net','customer pkr','customer sar','customer usd','sale price','selling price',
        'discount type','discount value','discount amount',
        'vendor / supplier','vendor/supplier','supplier / vendor','supplier cost','supplier price','supplier amount','supplier pkr','supplier sar','supplier usd',
        'agent commission','salesperson commission','sales commission',
        'gross margin','net contribution','net margin','margin %','margin percentage',
        'cost (sar)','cost sar','fx rate','exchange rate'
    ];

    function exactNode(text){
        const wanted = norm(text);
        return Array.from(document.querySelectorAll('h1,h2,h3,h4,h5,h6,.card-title,strong,span,div'))
            .filter(el => !policy.contains(el) && el.children.length <= 4)
            .find(el => norm(el.textContent) === wanted) || null;
    }

    function hotelCards(){
        const all = Array.from(document.querySelectorAll('.card, section'));
        return all.filter(el => {
            if (policy.contains(el)) return false;
            const t = norm(el.textContent);
            if (!t) return false;
            return t.includes('hotel stay') || t.includes('hotel accommodation') || t.includes('accommodation document readiness');
        });
    }

    function isCommercialText(text){
        const t = norm(text);
        return commercialTerms.some(term => t.includes(term));
    }

    function neutralize(container){
        if (!container) return;
        container.querySelectorAll('input,select,textarea').forEach(el => {
            const key = ((el.getAttribute('name') || el.id || '') + ' ' + (el.previousElementSibling?.textContent || '')).toLowerCase();
            if (/price|sale|cost|amount|discount|commission|margin|fx|exchange/.test(key)) {
                if (el.tagName === 'SELECT') {
                    const zeroLike = Array.from(el.options).find(o => /^(none|no discount|0)$/i.test((o.value || o.textContent || '').trim()));
                    if (zeroLike) el.value = zeroLike.value;
                } else if ((el.getAttribute('type') || '').toLowerCase() === 'number') {
                    el.value = '0';
                }
            }
        });
    }

    function smallestFieldContainer(label, card){
        let node = label.parentElement;
        let best = node;
        for (let i=0; i<5 && node && node !== card; i++) {
            const controls = node.querySelectorAll('input,select,textarea').length;
            const textLength = norm(node.textContent).length;
            if (controls >= 1 && controls <= 3 && textLength < 260) best = node;
            node = node.parentElement;
        }
        return best;
    }

    function hideCommercialInCard(card){
        if (!card) return;

        // Primary pass: labels and form field wrappers.
        card.querySelectorAll('label').forEach(label => {
            if (!isCommercialText(label.textContent)) return;
            const group = smallestFieldContainer(label, card);
            neutralize(group);
            if (group) {
                group.dataset.erp10288CommercialHidden = '1';
                group.style.display = 'none';
            }
        });

        // Secondary pass: summary chips/metric rows that do not use labels.
        Array.from(card.querySelectorAll('div,span,strong,small')).forEach(el => {
            if (el.children.length > 5 || !isCommercialText(el.textContent)) return;
            let node = el;
            for (let i=0; i<4 && node?.parentElement && node.parentElement !== card; i++) {
                const parent = node.parentElement;
                const text = norm(parent.textContent);
                const controls = parent.querySelectorAll('input,select,textarea').length;
                if (text.length < 320 && controls <= 4) node = parent;
                else break;
            }
            if (node && node !== card) {
                neutralize(node);
                node.dataset.erp10288CommercialHidden = '1';
                node.style.display = 'none';
            }
        });

        // Known name/id safety net. We deliberately keep fields enabled so
        // existing Laravel validation still receives them; amounts are zeroed.
        card.querySelectorAll('input,select,textarea').forEach(el => {
            const key = (el.getAttribute('name') || el.id || '').toLowerCase();
            if (!/customer.*price|sale.*amount|supplier.*cost|supplier.*amount|discount|commission|margin|fx_rate|exchange_rate/.test(key)) return;
            const group = smallestFieldContainer(el.closest('label') || el, card);
            neutralize(group || el.parentElement);
            if (group && group !== card) group.style.display = 'none';
        });
    }

    function placePolicy(){
        if (policy.dataset.moved === '1') return true;
        const heading = exactNode('Hotel Stays');
        if (!heading) return false;
        const headerCard = heading.closest('.card') || heading.parentElement;
        if (!headerCard?.parentElement) return false;
        headerCard.insertAdjacentElement('afterend', policy);
        policy.dataset.moved = '1';
        policy.style.display = 'block';
        return true;
    }

    function apply(){
        const placed = placePolicy();
        const cards = hotelCards();
        cards.forEach(hideCommercialInCard);
        return placed || cards.length > 0;
    }

    function boot(){
        apply();
        [100,300,700,1500].forEach(ms => setTimeout(apply, ms));
        const observer = new MutationObserver(apply);
        observer.observe(document.body, {childList:true,subtree:true});
        setTimeout(() => observer.disconnect(), 6000);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, {once:true});
    else boot();
})();
</script>
@endif
