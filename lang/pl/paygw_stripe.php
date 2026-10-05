<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Strings for component 'paygw_stripe', language 'pl'
 *
 * Complete translation shipped with the plugin. The Moodle language pack (AMOS) translates only
 * a few of these strings; Moodle loads this file first and then the language pack, so strings
 * present in the language pack override the ones below, and all the others come from here.
 *
 * @package    paygw_stripe
 * @copyright  2021 Alex Morris <alex@navra.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['pluginname'] = 'Stripe';
$string['pluginname_desc'] = 'Wtyczka Stripe umożliwia przyjmowanie płatności przez Stripe.';
$string['gatewayname'] = 'Stripe';
$string['apikey'] = 'Klucz API';
$string['apikey_help'] = 'Klucz API, którym wtyczka identyfikuje się w Stripe (klucz publiczny, pk_...).';
$string['secretkey'] = 'Klucz tajny';
$string['secretkey_help'] = 'Klucz tajny do uwierzytelniania w Stripe (sk_... lub klucz ograniczony rk_...).';
$string['paymentmethods'] = 'Metody płatności';
$string['usedynamicpaymentmethods'] = 'Używaj dynamicznych metod płatności';
$string['usedynamicpaymentmethods_desc'] = 'Stripe sam wyświetla metody płatności włączone w panelu Stripe (Ustawienia &gt; Metody płatności), dobrane do waluty, kwoty i lokalizacji klienta. Gdy ta opcja jest włączona, poniższa lista „Metody płatności” jest ignorowana.';
$string['usedynamicpaymentmethods_help'] = 'Gdy opcja jest włączona, wtyczka nie wysyła do Stripe stałej listy metod płatności. Zamiast tego korzysta z metod włączonych i uporządkowanych w panelu Stripe. Dzięki temu można dodawać i usuwać metody (np. BLIK, P24, Apple Pay, Google Pay) bez zmiany ustawień wtyczki. Gdy opcja jest wyłączona, oferowane są tylko metody zaznaczone na liście „Metody płatności”.';
$string['allowpromotioncodes'] = 'Zezwalaj na kody promocyjne';
$string['gatewaydescription'] = 'Stripe to autoryzowany operator płatności obsługujący transakcje kartami płatniczymi.';
$string['stripeaccount'] = 'ID konta Stripe';
$string['stripeaccount_help'] = 'Używane do brandingu strony płatności przy obciążeniach bezpośrednich (direct charges).';
$string['paymentsuccessful'] = 'Płatność zakończona powodzeniem';
$string['paymentcancelled'] = 'Płatność została anulowana';
$string['paymentpending'] = 'Płatność jest w trakcie realizacji. Zostaniesz zapisany na kurs, gdy płatność zostanie zaksięgowana.';
$string['customerdescription'] = 'ID użytkownika Moodle: {$a}';
$string['enableautomatictax'] = 'Włącz automatyczne podatki';
$string['enableautomatictax_desc'] = 'Automatyczny podatek musi być włączony i skonfigurowany w panelu Stripe.';
$string['taxmode'] = 'Tryb podatku';
$string['taxmode_help'] = 'Sposób naliczania VAT/podatku od płatności. Tryby wykluczają się wzajemnie (Stripe nie pozwala łączyć podatku automatycznego z ręcznymi stawkami):<br/>
<b>Bez podatku</b> – wtyczka nie dolicza podatku.<br/>
<b>Stripe Tax (automatyczny)</b> – Stripe oblicza podatek automatycznie na podstawie rejestracji podatkowych i lokalizacji klienta. Wymaga skonfigurowania Stripe Tax w panelu (adres sprzedawcy, rejestracje podatkowe i kod podatkowy produktu inny niż domyślny niepodlegający opodatkowaniu).<br/>
<b>Ręczna stawka podatku</b> – do każdej pozycji stosowana jest stała stawka utworzona w panelu Stripe (np. polski VAT 23%). To bezpłatna funkcja „Stawki podatkowe”, która nie wymaga Stripe Tax.';
$string['taxmode:none'] = 'Bez podatku';
$string['taxmode:automatic'] = 'Stripe Tax (automatyczny)';
$string['taxmode:manual'] = 'Ręczna stawka podatku';
$string['manualtaxrate'] = 'ID ręcznej stawki podatku';
$string['manualtaxrate_help'] = 'ID stawki podatkowej Stripe stosowanej do każdej pozycji, w formacie <code>txr_...</code>. Stawkę tworzy się w panelu Stripe w sekcji Katalog produktów &gt; Stawki podatkowe (tam ustawia się kraj, procent i to, czy podatek jest wliczony w cenę). ID zależy od trybu: stawka z trybu testowego nie działa w trybie produkcyjnym, więc użyj stawki utworzonej na tym samym koncie i w tym samym trybie co klucze tej bramki. Używane tylko w trybie „Ręczna stawka podatku”.';
$string['manualtaxrate_required'] = 'W trybie „Ręczna stawka podatku” trzeba podać ID stawki podatkowej.';
$string['defaulttaxbehavior'] = 'Domyślne naliczanie podatku';
$string['defaulttaxbehavior_help'] = 'Czy podatek Stripe Tax jest domyślnie wliczony w cenę, czy doliczany do niej. Dotyczy tylko trybu automatycznego; przy ręcznych stawkach ustawia się to w samej stawce w panelu Stripe.';
$string['profilecat'] = 'Subskrypcje płatności Stripe';
$string['cancelsubscriptions'] = 'Zmień subskrypcje';
$string['subscriptions'] = 'Subskrypcje';
$string['subscriptionsuccessful'] = 'Subskrypcja została aktywowana. Subskrypcjami Stripe możesz zarządzać na stronie swojego profilu.';
$string['paymenttype'] = 'Typ płatności';
$string['paymenttype:onetime'] = 'Jednorazowo';
$string['paymenttype:subscription'] = 'Subskrypcja';
$string['subscriptioninterval'] = 'Okres subskrypcji';
$string['customsubscriptioninterval'] = 'Własny okres subskrypcji';
$string['customsubscriptionintervalcount'] = 'Liczba jednostek własnego okresu subskrypcji';
$string['customsubscriptionintervalcount_help'] = '';
$string['anchoredbilling'] = 'Rozliczaj od początku okresu subskrypcji (stały dzień rozliczenia).';
$string['anchoredbilling_help'] = 'Np. przy subskrypcji miesięcznej opłata pobierana jest 1. dnia każdego miesiąca. Jeśli użytkownik zapisze się w połowie miesiąca, zapłaci proporcjonalną kwotę za okres od dnia zapisu do końca miesiąca.';
$string['trialperiod'] = 'Okres próbny';
$string['trialperiod_help'] = 'Np. pierwszy okres jest bezpłatny. Jeśli subskrypcja zostanie utworzona 24 kwietnia, miesiąc jest bezpłatny, a rozliczenia zaczynają się 24 maja.<br/>
    Jeśli rozliczenie następuje na początku okresu (np. 1. dnia miesiąca), kwota proporcjonalna nie jest pobierana. Jeśli użytkownik zapisze się 24 kwietnia, kwiecień jest bezpłatny, a rozliczenia zaczynają się 1 maja.';
$string['failedtosetdefaultpaymentmethod'] = 'Nie udało się ustawić metody płatności dla subskrypcji. Spróbuj ponownie.';
$string['subscriptionerror'] = 'Wystąpił błąd podczas tworzenia subskrypcji. Skontaktuj się z administratorem serwisu.';
$string['cancelsubscription'] = 'Anuluj subskrypcję';
$string['cancelsubscriptionconfirm'] = 'Czy na pewno chcesz anulować tę subskrypcję?';
$string['product'] = 'Produkt';
$string['fee'] = 'Opłata';
$string['scheduledrenewal'] = 'Planowane odnowienie';
$string['status'] = 'Status';
$string['updatepaymentmethod'] = 'Zmień metodę płatności';
$string['cancel'] = 'Anuluj';
$string['subscriptionssubheading'] = 'Na tej stronie znajdziesz wykupione subskrypcje. Możesz je tutaj anulować – anulowanie działa natychmiast i nie będzie już można wejść do kursu.';

$string['customsubscriptioninterval:day'] = 'Dzień';
$string['customsubscriptioninterval:week'] = 'Tydzień';
$string['customsubscriptioninterval:month'] = 'Miesiąc';
$string['customsubscriptioninterval:year'] = 'Rok';

$string['subscriptionperiod:daily'] = 'Codziennie';
$string['subscriptionperiod:weekly'] = 'Co tydzień';
$string['subscriptionperiod:monthly'] = 'Co miesiąc';
$string['subscriptionperiod:every3months'] = 'Co 3 miesiące';
$string['subscriptionperiod:every6months'] = 'Co 6 miesięcy';
$string['subscriptionperiod:yearly'] = 'Co rok';
$string['subscriptionperiod:custom'] = 'Własny';

$string['subscriptionstatus:active'] = 'Aktywna';
$string['subscriptionstatus:past_due'] = 'Zaległa płatność';
$string['subscriptionstatus:unpaid'] = 'Nieopłacona';
$string['subscriptionstatus:canceled'] = 'Anulowana';
$string['subscriptionstatus:incomplete'] = 'Niekompletna';
$string['subscriptionstatus:incomplete_expired'] = 'Wygasła';
$string['subscriptionstatus:trialing'] = 'Okres próbny';
$string['subscriptionstatus:paused'] = 'Wstrzymana';

$string['payment:successful:subject'] = 'Płatność zakończona powodzeniem';
$string['payment:successful:message'] = 'Twoja płatność została zaksięgowana. Możesz teraz przejść do: {$a->url}';
$string['payment:failed:subject'] = 'Płatność nieudana';
$string['payment:failed:message'] = 'Nie udało się zaksięgować Twojej płatności. Sprawdź dane płatności i spróbuj ponownie.';

$string['messageprovider:payment_successful'] = 'Potwierdzenie zaksięgowania płatności odroczonej';
$string['messageprovider:payment_failed'] = 'Powiadomienie o nieudanej płatności odroczonej';

$string['taxbehavior:exclusive'] = 'Doliczany do ceny';
$string['taxbehavior:inclusive'] = 'Wliczony w cenę';

$string['paymentmethod:card'] = 'Karta';
$string['paymentmethod:alipay'] = 'Alipay';
$string['paymentmethod:bancontact'] = 'Bancontact';
$string['paymentmethod:eps'] = 'EPS';
$string['paymentmethod:giropay'] = 'giropay';
$string['paymentmethod:ideal'] = 'iDEAL';
$string['paymentmethod:p24'] = 'P24';
$string['paymentmethod:sepa_debit'] = 'Polecenie zapłaty SEPA';
$string['paymentmethod:sofort'] = 'Sofort';
$string['paymentmethod:upi'] = 'UPI';
$string['paymentmethod:netbanking'] = 'NetBanking';
$string['paymentmethod:wechat_pay'] = 'WeChat Pay';
$string['paymentmethod:klarna'] = 'Klarna';

$string['privacy:metadata:stripe_customers'] = 'Przechowuje powiązanie użytkowników Moodle z obiektami klientów w Stripe';
$string['privacy:metadata:stripe_customers:userid'] = 'ID użytkownika Moodle';
$string['privacy:metadata:stripe_customers:customerid'] = 'ID klienta zwrócone przez Stripe';

$string['privacy:metadata:stripe_intents'] = 'Przechowuje dane intencji płatności w celu śledzenia historii płatności';
$string['privacy:metadata:stripe_intents:userid'] = 'ID użytkownika Moodle';

$string['privacy:metadata:stripe_subscriptions'] = 'Przechowuje powiązanie subskrypcji w Moodle z obiektami subskrypcji w Stripe';
$string['privacy:metadata:stripe_subscriptions:userid'] = 'ID użytkownika Moodle';

$string['stripeinvoices'] = 'Faktury i potwierdzenia płatności Stripe';
$string['purchaseunavailable_coursehidden'] = 'Ten kurs nie jest już dostępny w sprzedaży.';
$string['purchaseunavailable_enroldisabled'] = 'Zapisy na ten kurs są zamknięte, więc nie można go kupić.';
$string['purchaseunavailable_ended'] = 'Zapisy na ten kurs już się zakończyły, więc nie można go kupić.';
$string['purchaseunavailable_notstarted'] = 'Zapisy na ten kurs jeszcze się nie rozpoczęły, więc nie można go teraz kupić.';
