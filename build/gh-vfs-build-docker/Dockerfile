ARG IMAGE=ubuntu@sha256:57df66b9fc9ce2947e434b4aa02dbe16f6685e20db0c170917d4a1962a5fe6a9
FROM $IMAGE

ENV DEBIAN_FRONTEND noninteractive
ENV TERM=xterm
ENV PERL_MM_USE_DEFAULT=1
ENV PYTHONUTF8=1

RUN apt-get update && apt-get install -y curl unzip samba samba-common samba-common-bin samba-vfs-modules build-essential python3-dev libgnutls28-dev pkg-config flex locales comerr-dev heimdal-multidev libtdb-dev libldb-dev libtalloc-dev libtevent-dev \
	# Clean apt, /tmp
	&& rm -rf /var/lib/apt/lists/* /tmp/* \
	&& perl -MCPAN -e 'install Parse::Yapp::Driver' \
	&& locale-gen en_US.UTF-8

ENV LANG en_US.UTF-8
ENV LANGUAGE en_US:en
ENV LC_ALL en_US.UTF-8

WORKDIR /usr/share/greyhole/

COPY build_vfs.sh .
COPY vfs_greyhole-samba-* samba-module/
COPY wscript-samba-* samba-module/

ARG SAMBA_VERSION
ARG PACKAGE
RUN if [ -d "/usr/include/samba-4.0/" ]; then cp /usr/include/samba-4.0/* /usr/include/ ; fi # Surely there is a way to tell Samba to look in /usr/include/samba-4.0/, to load the ldb*.h files..?
RUN GREYHOLE_INSTALL_DIR=$(pwd) bash ./build_vfs.sh $SAMBA_VERSION $PACKAGE || (cat vfs-build/samba-*/gh_vfs_build.log && false)

RUN cp -Lr vfs-build/samba-*/bin/shared ./samba-bin-shared \
	&& mv vfs-build/samba-*/greyhole-samba*.so . \
	&& rm -rf vfs-build/samba-*/* \
	&& mkdir "vfs-build/$(ls -1 vfs-build | grep samba-)/bin" \
	&& mv samba-bin-shared "vfs-build/$(ls -1 vfs-build | grep samba-)/bin/shared" \
	&& mv greyhole-samba*.so "vfs-build/$(ls -1 vfs-build | grep samba-)/" \
	&& (cd vfs-build/ ; rm -rf .git* .lock-wscript .testr.conf .ycm_extra_conf.py) \
	&& (cd "vfs-build/$(ls -1 vfs-build | grep samba-)/" ; rm -rf .git* .lock-wscript .testr.conf .ycm_extra_conf.py)
