/**
 * HotPotato Plugin - Event JS
 *
 * @author Tim Gunter <tim@vanillaforums.com>
 * @copyright 2010-2014 Vanilla Forums Inc
 * @license Proprietary
 */

String.prototype.toFormatTime = function () {
    var inSeconds = parseInt(this);
    var days = inSeconds ? Math.floor(inSeconds / 84600) : 0;
    inSeconds = inSeconds - (days * 84600);

    var hours = inSeconds ? Math.floor(inSeconds / 3600) : 0;
    inSeconds = inSeconds - (hours * 3600);

    var minutes = inSeconds ? Math.floor(inSeconds / 60) : 0;
    inSeconds = inSeconds - (minutes * 60);

    var seconds = inSeconds;

    var time = [];
    if (days > 0) {
        time.push(days+'d');
    }

    if (hours > 0) {
        time.push(hours+'h');
    }

    if (minutes > 0) {
        time.push(minutes+'m');
    }

    if (seconds > 0 || time.length === 0) {
        time.push(seconds+'s');
    }

    return time.join(' ');
};

jQuery(document).ready(function($) {

    // Show timer for user
    var timerExpiry = gdn.definition('PotatoExpiry', 'none');
    if (timerExpiry && timerExpiry !== 'none') {
        timerExpiry = parseInt(timerExpiry);

        var timerDate = new Date();
        var expiryTime = timerDate.getTime() + (timerExpiry * 1000);
        jQuery(document).data('PotatoExpiryTime', expiryTime);

        refreshPotato();
    }

});

function refreshPotato() {

    endPotato();
    var currentInterval = setInterval(updatePotato, 1000);
    jQuery(document).data('PotatoExpiryInterval', currentInterval);

}

function updatePotato() {
    var timerDate = new Date();
    var expiryTime = jQuery(document).data('PotatoExpiryTime');
    var expiryDelay = (expiryTime - timerDate.getTime()) / 1000;
    if (expiryDelay <= 1) {
        endPotato();
    }
    if (expiryDelay <= 0) {
        return;
    }

    var expiryFormatTime = String(expiryDelay).toFormatTime();
    var potatoName = gdn.definition('PotatoName', 'unknown potato');
    if (expiryDelay >= 1) {
        var complianceMessage = '<div class="Compliance" style="">Toss the <b>'+potatoName+'</b> within the next <span style="color:#51CEFF;">'+expiryFormatTime+'</span></div>';
    } else {
        var complianceMessage = '<div class="Compliance" style="color: #9f362e;">Oh god...</div>';
    }

    var potatoTimerInform = jQuery('#PotatoTimer');
    if (!potatoTimerInform.length) {
        var potatoInform = {'InformMessages':[
            {
                'CssClass':             'PotatoTimer Dismissable',
                'id':                   'PotatoTimer',
                'DismissCallbackUrl':   gdn.url('/plugin/hotpotato/dismiss'),
                'Message':              complianceMessage
            }
        ]};
        gdn.inform(potatoInform);
        return;
    }

    potatoTimerInform.find('.InformMessage .Compliance').html(complianceMessage);
    return;
}

function endPotato() {
    var currentInterval = $(document).data('PotatoExpiryInterval');
    if (currentInterval) {
        clearInterval(currentInterval);
    }
}