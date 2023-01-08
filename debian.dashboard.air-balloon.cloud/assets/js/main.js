$(document).ready(function () {
    // Setup - add a text input to each footer cell
    $('#packages tfoot th').each(function () {
        var title = $(this).text();
        $(this).html('<input class="form-control" style="width: min-content;" type="text" placeholder="Search ' + title + '" />');
    });
    $('#packages thead th').each(function () {
        var title = $(this).text();
        $(this).html('<input class="form-control" style="width: min-content;" type="text" placeholder="Search ' + title + '" />');
    });
 
    // DataTable
    var table = $('#packages').DataTable({
        initComplete: function () {
            // Apply the search
            this.api()
                .columns()
                .every(function () {
                    var that = this;
 
                    $('input', this.footer()).on('keyup change clear', function () {
                        if (that.search() !== this.value) {
                            that.search(this.value).draw();
                        }
                    });
                    $('input', this.header()).on('keyup change clear', function () {
                        if (that.search() !== this.value) {
                            that.search(this.value).draw();
                        }
                    });
                });
        },
    });
});