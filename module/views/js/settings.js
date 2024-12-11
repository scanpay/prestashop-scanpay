/**
 * @author    Scanpay <contact@scanpay.dk>
 * @copyright Scanpay ApS. All rights reserved.
 * @license   https://opensource.org/licenses/MIT MIT License
 */

document.addEventListener('DOMContentLoaded', function () {
    const dropdown = document.getElementById('scanpay--captureonorderstatus--dropdown');

    document.addEventListener('click', function () {
        dropdown.classList.remove('scanpay--show');
    });

    document.getElementById('scanpay--captureonorderstatus--add').addEventListener('click', function (e) {
        dropdown.classList.toggle('scanpay--show');
        e.stopPropagation();
    });

    function mkStatus(id, name, addBtn) {
        var li = document.createElement('li');
        li.textContent = name;
        var span = document.createElement('span');
        span.textContent = '\u00D7';
        span.addEventListener('click', function (e) {
            li.parentNode.removeChild(li);
            document.querySelector('#SCANPAY_CAPTURE_ON_ORDER_STATUS\\[\\] option[value="' + id + '"]').removeAttribute('selected');
            addBtn.classList.remove('scanpay--usedstatus');
        });
        li.appendChild(span);
        document.getElementById('scanpay--captureonorderstatus--list').appendChild(li);
        addBtn.classList.add('scanpay--usedstatus');
    }

    document.querySelectorAll('#SCANPAY_CAPTURE_ON_ORDER_STATUS\\[\\] option').forEach(function (opt) {
        var li = document.createElement('li');
        li.textContent = opt.textContent;
        li.addEventListener('click', function () {
            if (li.classList.contains('scanpay--usedstatus')) { return; }
            document.querySelector('#SCANPAY_CAPTURE_ON_ORDER_STATUS\\[\\] option[value="' + opt.value + '"]').setAttribute('selected', '');
            mkStatus(opt.value, opt.textContent, li);
        });
        dropdown.appendChild(li);
        if (opt.selected) {
            mkStatus(opt.value, opt.textContent, li);
        }
    });

    // Click should select all text in the input field
    document.getElementById('scanpay--pingurl--input').addEventListener('click', function () {
        this.focus();
        this.select();
    });
});
