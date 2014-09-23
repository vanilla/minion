/**
 * Valentines Plugin - Event JS
 * 
 */

String.prototype.toFormatTime = function () {
   sec_numb    = parseInt(this);
   var hours   = Math.floor(sec_numb / 3600);
   var minutes = Math.floor((sec_numb - (hours * 3600)) / 60);
   var seconds = sec_numb - (hours * 3600) - (minutes * 60);

   if (hours   < 10) {hours   = "0"+hours;}
   if (minutes < 10) {minutes = "0"+minutes;}
   if (seconds < 10) {seconds = "0"+seconds;}
   var time    = hours+':'+minutes+':'+seconds;
   return time;
}

jQuery(document).ready(function($) {
   
   // Show timer for user
   var TimerExpiry = gdn.definition('ValentinesExpiry', 'none');
   if (TimerExpiry && TimerExpiry != 'none') {
      TimerExpiry = parseInt(TimerExpiry);

      var TimerDate = new Date();
      var ExpiryTime = TimerDate.getTime() + (TimerExpiry*1000);
      $(document).data('ValentinesExpiryTime', ExpiryTime);

      RefreshValentines();
   }
   
   $('.Item.ArrowCache').each(function(i,el){
      var Item = $(el);
      var CacheLink = Item.find('a.FallenCupidLink');
      
      Item.on('click', 'a.FallenCupidLink', function(e){
         e.preventDefault();
         var et = $(e.target);
         
         $.ajax({
            url: et.attr('href'),
            dataType: 'json',
            method: 'GET',
            success: function(json) {
               json = $.postParseJson(json);
               var processedTargets = false;
               if (json.Targets && json.Targets.length > 0)
                  gdn.processTargets(json.Targets);
               
               gdn.inform(json);
            }
         });
         
         return false;
      });
   });
   
   function RefreshValentines() {
      
      EndValentines();
      var CurrentInterval = setInterval(UpdateValentines,1000);
      $(document).data('ValentinesExpiryInterval', CurrentInterval);
      
   }
   
   function UpdateValentines() {
      var TimerDate = new Date();
      var ExpiryTime = $(document).data('ValentinesExpiryTime');
      var ExpiryDelay = (ExpiryTime - TimerDate.getTime()) / 1000;
      if (ExpiryDelay < 0)
         return EndValentines();
      
      var ExpiryFormatTime = String(ExpiryDelay).toFormatTime();
      var ConversationID = gdn.definition('ValentinesConversation', 0);
      var ComplianceMessage = '<div class="Compliance" style="">Compliance in: <a href="/messages/'+ConversationID+'"><span style="color:#51CEFF;">'+ExpiryFormatTime+'</span></a></div>';
         
      var ValentinesTimerInform = $('#ValentinesTimer');
      if (!ValentinesTimerInform.length) {
         var ValentinesInform = {'InformMessages':[
            {
               'CssClass':             'ValentinesTimer Dismissable',
               'id':                   'ValentinesTimer',
               'DismissCallbackUrl':   gdn.url('/plugin/valentines/dismiss'),
               'Message':              ComplianceMessage
            }
         ]}
         gdn.inform(ValentinesInform);
         return;
      }
      
      ValentinesTimerInform.find('.InformMessage .Compliance').html(ComplianceMessage);
      return;
   }
   
   function EndValentines() {
      var CurrentInterval = $(document).data('ValentinesExpiryInterval');
      if (CurrentInterval)
         clearInterval(CurrentInterval);
   }
   
});