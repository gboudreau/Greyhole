

<h2 class="mt-4 mb-4">Setup Samba Shares</h2>

<div>
    For each of your shares that you want Greyhole to manage, choose <code>Yes</code> in the <code>Greyhole-enabled</code> column,<br/>
    then choose the number of file copies you'd like Greyhole to keep.<br/>
    Of note: This is <code>not</code> the number of duplicates! 2 copies = 1 duplicate
</div>

<div class="row mt-3">
    <div class="col-12">
        <?php
        define('SKIP_TITLE', TRUE);
        include 'web-app/views/samba_shares.php';
        ?>
    </div>
    <script>
        let last_known_config_hash, last_known_config_hash_samba;
    </script>
</div>
