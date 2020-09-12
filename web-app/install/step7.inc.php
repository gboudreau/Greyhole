
<h2 class="mt-4 mb-4">
    All done!
</h2>

<div class="mt-3">
    You can now use the web UI to monitor the status of you install.
</div>
<div class="mt-3">
    Of note: The web UI can be used by anyone during initial setup, but requires a donation for continued use.<br/>
    For details, see the <a href="https://github.com/gboudreau/Greyhole/wiki/Admin-Web-UI" target="_blank">Admin Web UI</a> page on the Wiki.
</div>
<div class="mt-3">
    If you use any applications that needs to use files on your shares locally (on the same server), you'll need to <a href="https://github.com/gboudreau/Greyhole/wiki/Mountshareslocally" target="_blank">mount the shares locally</a>, and point your applications to those mounts (<code>/mnt/samba/ShareName</code>).<br/>
    You should never work on the files in your storage pool directories, or the symlinks in your shared directories.
</div>

<button class="btn btn-primary mt-3" onclick="location.href='../'">Continue to the web admin UI</button>
