Greyhole
========

Greyhole is an application that uses Samba to create a storage pool of all your available hard drives (whatever their size, however they're connected), and allows you to create redundant copies of the files you store, in order to prevent data loss when part of your hardware fails.

Installation
------------

1. Using `apt` (Ubuntu, Debian) or `yum` (CentOS, Fedora, RHEL):
    
    ```curl -Ls https://bit.ly/greyhole-package | sudo bash```

2. Follow the instructions from the [USAGE](https://raw.github.com/gboudreau/Greyhole/master/USAGE) file.
   There is also a copy of this file in `/usr/share/greyhole/USAGE`

Links
-----
* [Official website](https://www.greyhole.net/)
* [Support](https://greyhole.freshdesk.com/) on FreshDesk
* The [wiki on Github](https://github.com/gboudreau/Greyhole/wiki#get-help-or-resolve-a-problem) is filled with useful information, including a FAQ.
* Search the [Issues on Github](https://github.com/gboudreau/Greyhole/issues?q=is%3Aissue), to see if someone else had the same problem in the past, and what the resolution/workarounds were suggested.

Features
--------
__JBOD concatenation storage pool__

Configure as many hard drives as you'd like to be included in your pool. Your storage pool size will be the sum of the free space in all the hard drives you include. Your hard drives can be internal, external (USB, e-Sata, Firewire...), or even mount of remote file systems, and you can include hard drives of any size in your pool.

__Per-share redundancy__

For each of your shares that use the space of your storage pool, indicate how many copies of each file you want to keep. Each of those copies will be stored in a different hard drive, in order to prevent data loss when one or more hard drives fail. For very important files, you can even specify you'd like to keep copies on all available hard drives.

__Easily recoverable files__

Greyhole file copies are regular files, visible on any machine, without any hardware or software required. If you take out one hard drive from your pool, and mount it anywhere else, you'll be able to see all the files that Greyhole stored on it. They will have the same filenames, and they'll be in the same directories you'd expect them to be.

Documentation
-------------
The [GitHub Wiki](https://github.com/gboudreau/Greyhole/wiki) contains the Greyhole documentation.
