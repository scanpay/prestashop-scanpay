/**
 * @author    Scanpay <contact@scanpay.dk>
 * @copyright Scanpay ApS. All rights reserved.
 * @license   https://opensource.org/licenses/MIT MIT License
 */

$(document).ready(function () {
    $(document).bind('click', function () {
        $('#scanpay--captureonorderstatus--dropdown').removeClass('scanpay--show');
    });

    $('#scanpay--captureonorderstatus--add').bind('click', function (e) {
        $('#scanpay--captureonorderstatus--dropdown').toggleClass('scanpay--show');
        e.stopPropagation();
    });

    function mkStatus(id, name, addBtn) {
        var li = document.createElement('li');
        li.textContent = name;
        var span = document.createElement('span');
        span.textContent = '\u00D7';
        span.addEventListener('click', function (e) {
            li.parentNode.removeChild(li);
            $('#SCANPAY_CAPTURE_ON_ORDER_STATUS\\[\\] option[value="' + id + '"]').removeAttr('selected');
            addBtn.classList.remove('scanpay--usedstatus');
        });
        li.appendChild(span);
        $('#scanpay--captureonorderstatus--list').append(li);
        addBtn.classList.add('scanpay--usedstatus');
    }

    $('#SCANPAY_CAPTURE_ON_ORDER_STATUS\\[\\] option').each(function (_i, opt) {
        var li = document.createElement('li');
        li.textContent = opt.textContent
        li.addEventListener('click', function () {
            if (li.classList.contains('scanpay--usedstatus')) { return; }
            $('#SCANPAY_CAPTURE_ON_ORDER_STATUS\\[\\] option[value="' + opt.value + '"]').attr('selected', '');
            mkStatus(opt.value, opt.textContent, li);
        });
        $('#scanpay--captureonorderstatus--dropdown').append(li);
        if (opt.selected) {
            mkStatus(opt.value, opt.textContent, li);
        }
    });
});
