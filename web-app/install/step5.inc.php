
<div class="mt-4 mb-4">
    <?php include('web-app/views/storage_pool.php') ?>
    <script>
        let last_known_config_hash, last_known_config_hash_samba;
        defer(function () {
            resizeSPDrivesUsageGraphs();
            $(window).resize(function() {
                resizeSPDrivesUsageGraphs();
            });
        });
    </script>
</div>
