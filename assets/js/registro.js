(function ($) {
    'use strict';

    $('#register-form').on('submit', function (event) {
        event.preventDefault();
        var form = $(this);
        var alertBox = $('#form-alert');

        if (!this.checkValidity()) {
            this.reportValidity();
            return;
        }

        alertBox.addClass('d-none').text('');
        FacturaSV.showFieldErrors(form, {});
        FacturaSV.setLoading(form, true);

        $.ajax({
            url: form.attr('action'),
            method: 'POST',
            data: new FormData(this),
            processData: false,
            contentType: false,
            dataType: 'json'
        }).done(function (response) {
            if (response.success) {
                window.location.assign(response.redirect);
            }
        }).fail(function (xhr) {
            var response = xhr.responseJSON || {};
            alertBox.text(response.message || 'No fue posible registrar la empresa. Inténtelo nuevamente.').removeClass('d-none');
            FacturaSV.showFieldErrors(form, response.errors || {});
            FacturaSV.setLoading(form, false);
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    });
})(jQuery);
