Greyhole
========

[![Code Climate](https://codeclimate.com/github/gboudreau/Greyhole.png)](https://codeclimate.com/github/gboudreau/Greyhole)

Greyhole is an application that uses Samba to create a storage pool of all your available hard drives (whatever their size, however they're connected), and allows you to create redundant copies of the files you store, in order to prevent data loss when part of your hardware fails.

Links
-----
* [Official website](https://www.greyhole.net/)
* [Support](http://support.greyhole.net)

Features
--------

__JBOD concatenation storage pool__

Configure as many hard drives as you'd like to be included in your pool. You're storage pool size will be the sum of the free space in all the hard drives you include. Your hard drives can be internal, external (USB, e-Sata, Firewire...), or even mount of remote file systems, and you can include hard drives of any size in your pool.

__Per-share redundancy__

For each of your shares that use the space of your storage pool, indicate how many copies of each file you want to keep. Each of those copies will be stored in a different hard drive, in order to prevent data loss when one or more hard drives fail. For very important files, you can even specify you'd like to keep copies on all available hard drives.

__Easily recoverable files__

Greyhole file copies are regular files, visible on any machine, without any hardware or software required. If you take out one hard drive from your pool, and mount it anywhere else, you'll be able to see all the files that Greyhole stored on it. They will have the same filenames, and they'll be in the same directories you'd expect them to be.

Documentation
-------------
The [GitHub Wiki](https://github.com/gboudreau/Greyhole/wiki) contains the Greyhole documentation.

Acks
----
Greyhole is developed mainly using a free open-source license of  
![PHPStorm](https://d3uepj124s5rcx.cloudfront.net/items/0V0z2p0e0K1D0F3t2r1P/logo_PhpStorm.png)  
kindly provided by [JetBrains](http://www.jetbrains.com/). Thanks guys!
