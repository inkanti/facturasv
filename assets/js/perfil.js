(function ($) {
    'use strict';

    $('#profile-password-form').on('submit', function (event) {
        event.preventDefault();
        var form = $(this);
        var alertBox = $('#profile-alert');

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
            dataType: 'json',
            data: form.serialize()
        }).done(function (response) {
            FacturaSV.setLoading(form, false);
            form[0].reset();
            $('#app-toast-message').text(response.message);
            new bootstrap.Toast(document.getElementById('app-toast')).show();
        }).fail(function (xhr) {
            FacturaSV.setLoading(form, false);
            var response = xhr.responseJSON || {};
            FacturaSV.showFieldErrors(form, response.errors || {});
            alertBox.text(response.message || 'No fue posible actualizar la contraseña.').removeClass('d-none');
        });
    });
})(jQuery);
