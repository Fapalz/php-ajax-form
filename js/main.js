//после загрузки DOM
$(function () {

  var forms = $('.ajax-form');
  forms.each(function( index ) {
    var FORM = new ProcessForm(this);
    FORM.init();
  });
});