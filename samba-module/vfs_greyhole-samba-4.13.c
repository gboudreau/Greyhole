/*
Copyright 2009-2020 Guillaume Boudreau, Edgars Binans

This file is part of Greyhole.

It was created based on vfs_extd_audit.c, by Tim Potter, Alexander
Bokovoy, John H Terpstra & Stefan (metze) Metzmacher.

Greyhole is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

Greyhole is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with Greyhole.  If not, see <http://www.gnu.org/licenses/>.
*/

#include "includes.h"
#include "system/filesys.h"
#include "smbd/smbd.h"
#include <gnutls/gnutls.h>
#include <gnutls/crypto.h>
#include "../lib/crypto/gnutls_helpers.h"

static int vfs_greyhole_debug_level = DBGC_VFS;

#undef DBGC_CLASS
#define DBGC_CLASS vfs_greyhole_debug_level

/* Function prototypes */

static int greyhole_connect(vfs_handle_struct *handle, const char *svc, const char *user);
static int greyhole_mkdirat(vfs_handle_struct *handle, struct files_struct *dirfsp, const struct smb_filename *smb_fname, mode_t mode);
static int greyhole_openat(vfs_handle_struct *handle, const struct files_struct *dirfsp, const struct smb_filename *smb_fname, struct files_struct *fsp, int flags, mode_t mode);
static ssize_t greyhole_pwrite(vfs_handle_struct *handle, files_struct *fsp, const void *data, size_t count, off_t offset);
static ssize_t greyhole_recvfile(vfs_handle_struct *handle, int fromfd, files_struct *tofsp, off_t offset, size_t n);
static struct tevent_req *greyhole_pwrite_send(struct vfs_handle_struct *handle, TALLOC_CTX *mem_ctx, struct tevent_context *ev, struct files_struct *fsp, const void *data, size_t n, off_t offset);
static ssize_t greyhole_pwrite_recv(struct tevent_req *req, struct vfs_aio_state *vfs_aio_state);
static int greyhole_close(vfs_handle_struct *handle, files_struct *fsp);
static int greyhole_renameat(vfs_handle_struct *handle, files_struct *srcfsp, const struct smb_filename *oldname, files_struct *dstfsp, const struct smb_filename *newname);
static int greyhole_linkat(vfs_handle_struct *handle, files_struct *srcfsp, const struct smb_filename *oldname, files_struct *dstfsp, const struct smb_filename *newname, int flags);
static int greyhole_unlinkat(vfs_handle_struct *handle, struct files_struct *dirfsp, const struct smb_filename *smb_fname, int flags);

/* Save formatted string to Greyhole spool */

static void gh_spoolf(const char* format, ...)
{
	FILE *spoolf;
	char filename[38];
	struct timeval tp;
	va_list args;

	gettimeofday(&tp, (struct timezone *) NULL);
	snprintf(filename, 37, "/var/spool/greyhole/%.0f", ((double) (tp.tv_sec)*1000000.0) + (((double) tp.tv_usec)));
	spoolf = fopen(filename, "wt");

	va_start(args, format);
	vfprintf(spoolf, format, args);
	va_end(args);

	fclose(spoolf);
}

void compute_hash(const char *str, char *out) {
	gnutls_hash_hd_t hash_hnd = NULL;
	uint8_t digest[gnutls_hash_get_len(GNUTLS_DIG_SHA1)];
	int rc;
    int n;

	GNUTLS_FIPS140_SET_LAX_MODE();

	rc = gnutls_hash_init(&hash_hnd, GNUTLS_DIG_SHA1);
	if (rc < 0) {
		snprintf(out, 1, "%s", "");
		goto out;
	}

	rc = gnutls_hash(hash_hnd, str, strlen(str));
	if (rc < 0) {
		gnutls_hash_deinit(hash_hnd, NULL);
		snprintf(out, 1, "%s", "");
		goto out;
	}

	gnutls_hash_deinit(hash_hnd, digest);

    for (n = 0; n < gnutls_hash_get_len(GNUTLS_DIG_SHA1); ++n) {
        snprintf(&(out[n*2]), 3, "%02x", (unsigned int)digest[n]);
    }

out:
	GNUTLS_FIPS140_SET_STRICT_MODE();
}

static const char *smb_fname_str(const struct smb_filename *smb_fname) {
	if (smb_fname == NULL) {
		return "";
	}
	return smb_fname->base_name;
}

/* VFS operations */

static struct vfs_fn_pointers vfs_greyhole_fns = {

	/* Disk operations */

	.connect_fn = greyhole_connect,

	/* Directory operations */

	.mkdirat_fn = greyhole_mkdirat,
	//.rmdir_fn = greyhole_rmdir, replaced with greyhole_unlinkat(.., AT_REMOVEDIR)

	/* File operations */

	.openat_fn = greyhole_openat,
	.pwrite_fn = greyhole_pwrite,
	.recvfile_fn = greyhole_recvfile,
	.pwrite_send_fn = greyhole_pwrite_send,
	.pwrite_recv_fn = greyhole_pwrite_recv,
	.close_fn = greyhole_close,
	.renameat_fn = greyhole_renameat,
	.linkat_fn = greyhole_linkat,
	.unlinkat_fn = greyhole_unlinkat
};

#define PO10_LIMIT (INT_MAX/10)

static int nDigits(int i)
{
  int n,po10;

  if (i < 0) i = -i;
  n=1;
  po10=10;
  while(i>=po10)
  {
    n++;
    if (po10 > PO10_LIMIT) break;
    po10*=10;
  }
  return n;
}

/* Implementation of vfs_ops.  Pass everything on to the default
operation but log event first. */

static int greyhole_connect(vfs_handle_struct *handle, const char *svc, const char *user)
{
	int result;

	if (!handle) {
		return -1;
	}

	result = SMB_VFS_NEXT_CONNECT(handle, svc, user);

	return result;
}

static int greyhole_mkdirat(vfs_handle_struct *handle, struct files_struct *dirfsp, const struct smb_filename *smb_fname, mode_t mode)
{
	int result;
	const struct loadparm_substitution *lp_sub = loadparm_s3_global_substitution();

	result = SMB_VFS_NEXT_MKDIRAT(handle, dirfsp, smb_fname, mode);

	if (result >= 0) {
		gh_spoolf("mkdir\n%s\n%s\n\n",
			lp_servicename(talloc_tos(), lp_sub, handle->conn->params->service),
			smb_fname->base_name);
	}

	return result;
}

static int greyhole_openat(vfs_handle_struct *handle, const struct files_struct *dirfsp, const struct smb_filename *smb_fname, struct files_struct *fsp, int flags, mode_t mode)
{
	int result;
	const struct loadparm_substitution *lp_sub = loadparm_s3_global_substitution();

	result = SMB_VFS_NEXT_OPENAT(handle, dirfsp, smb_fname, fsp, flags, mode);

	if (result >= 0) {
		if ((flags & O_WRONLY) || (flags & O_RDWR)) {
			gh_spoolf("open\n%s\n%s\n%d\n%s\n",
				lp_servicename(talloc_tos(), lp_sub, handle->conn->params->service),
				smb_fname->base_name,
				result,
				"for writing ");
		}
	}

	return result;
}

static ssize_t greyhole_pwrite(vfs_handle_struct *handle, files_struct *fsp, const void *data, size_t count, off_t offset)
{
	ssize_t result;
	FILE *spoolf;
	char filename[255];
	struct timeval tp;
	const struct loadparm_substitution *lp_sub = loadparm_s3_global_substitution();

	result = SMB_VFS_NEXT_PWRITE(handle, fsp, data, count, offset);

	if (result >= 0) {
		gettimeofday(&tp, (struct timezone *) NULL);
		char *share = lp_servicename(talloc_tos(), lp_sub, handle->conn->params->service);
		const char *fname = smb_fname_str(fsp->fsp_name);
		char md5[2*gnutls_hash_get_len(GNUTLS_DIG_SHA1)+1];
		compute_hash(fname, md5);
		snprintf(filename, 44 + strlen(share) + nDigits(fsp->fh->fd) + 2*gnutls_hash_get_len(GNUTLS_DIG_SHA1), "/var/spool/greyhole/mem/%.0f-%s-%d-%s",
			((double) (tp.tv_sec)*1000000.0),
			share,
			fsp->fh->fd,
			md5);
		spoolf = fopen(filename, "wt");
		fprintf(spoolf, "fwrite\n%s\n%d\n%s\n\n",
			share,
			fsp->fh->fd,
			fname);
		fclose(spoolf);
	}

	return result;
}

static ssize_t greyhole_recvfile(vfs_handle_struct *handle, int fromfd, files_struct *tofsp, off_t offset, size_t n)
{
	ssize_t result;
	FILE *spoolf;
	char filename[255];
	struct timeval tp;
	const struct loadparm_substitution *lp_sub = loadparm_s3_global_substitution();

	result = SMB_VFS_NEXT_RECVFILE(handle, fromfd, tofsp, offset, n);

	if (result >= 0) {
		gettimeofday(&tp, (struct timezone *) NULL);
		char *share = lp_servicename(talloc_tos(), lp_sub, handle->conn->params->service);
		const char *fname = smb_fname_str(tofsp->fsp_name);
		char md5[2*gnutls_hash_get_len(GNUTLS_DIG_SHA1)+1];
		compute_hash(fname, md5);
		snprintf(filename, 44 + strlen(share) + nDigits(tofsp->fh->fd) + 2*gnutls_hash_get_len(GNUTLS_DIG_SHA1), "/var/spool/greyhole/mem/%.0f-%s-%d-%s",
			((double) (tp.tv_sec)*1000000.0),
			share,
			tofsp->fh->fd,
			md5);
		spoolf = fopen(filename, "wt");
		fprintf(spoolf, "fwrite\n%s\n%d\n%s\n\n",
			share,
			tofsp->fh->fd,
            fname);
		fclose(spoolf);
	}

	return result;
}

struct greyhole_pwrite_state {
	vfs_handle_struct *handle;
	files_struct *fsp;
	ssize_t ret;
	struct vfs_aio_state vfs_aio_state;
};

static void greyhole_pwrite_done(struct tevent_req *subreq)
{
	struct tevent_req *req = tevent_req_callback_data(subreq, struct tevent_req);
	struct greyhole_pwrite_state *state = tevent_req_data(req, struct greyhole_pwrite_state);
	state->ret = SMB_VFS_PWRITE_RECV(subreq, &state->vfs_aio_state);
	TALLOC_FREE(subreq);
	tevent_req_done(req);
}

static struct tevent_req *greyhole_pwrite_send(struct vfs_handle_struct *handle, TALLOC_CTX *mem_ctx, struct tevent_context *ev, struct files_struct *fsp, const void *data, size_t n, off_t offset)
{
	struct tevent_req *req, *subreq;
	struct greyhole_pwrite_state *state;
	ssize_t result;
	FILE *spoolf;
	char filename[255];
	struct timeval tp;
	const struct loadparm_substitution *lp_sub = loadparm_s3_global_substitution();

	req = tevent_req_create(mem_ctx, &state, struct greyhole_pwrite_state);
	if (req == NULL) {
		return NULL;
	}
	state->handle = handle;
	state->fsp = fsp;

	subreq = SMB_VFS_NEXT_PWRITE_SEND(state, ev, handle, fsp, data, n, offset);
	if (tevent_req_nomem(subreq, req)) {
		return tevent_req_post(req, ev);
	}
	tevent_req_set_callback(subreq, greyhole_pwrite_done, req);

	gettimeofday(&tp, (struct timezone *) NULL);
	char *share = lp_servicename(talloc_tos(), lp_sub, handle->conn->params->service);
	const char *fname = smb_fname_str(fsp->fsp_name);
	char md5[2*gnutls_hash_get_len(GNUTLS_DIG_SHA1)+1];
	compute_hash(fname, md5);
	snprintf(filename, 44 + strlen(share) + nDigits(fsp->fh->fd) + 2*gnutls_hash_get_len(GNUTLS_DIG_SHA1), "/var/spool/greyhole/mem/%.0f-%s-%d-%s",
		((double) (tp.tv_sec)*1000000.0),
		share,
		fsp->fh->fd,
		md5);
	spoolf = fopen(filename, "wt");
	fprintf(spoolf, "fwrite\n%s\n%d\n%s\n\n",
		share,
		fsp->fh->fd,
		fname);
	fclose(spoolf);

	return req;
}

static ssize_t greyhole_pwrite_recv(struct tevent_req *req, struct vfs_aio_state *vfs_aio_state)
{
	struct greyhole_pwrite_state *state = tevent_req_data(req, struct greyhole_pwrite_state);

	if (tevent_req_is_unix_error(req, &vfs_aio_state->error)) {
		return -1;
	}

	*vfs_aio_state = state->vfs_aio_state;
	return state->ret;
}

static int greyhole_close(vfs_handle_struct *handle, files_struct *fsp)
{
	int result;
	const struct loadparm_substitution *lp_sub = loadparm_s3_global_substitution();

	result = SMB_VFS_NEXT_CLOSE(handle, fsp);

	if (result >= 0) {
		const char *fname = smb_fname_str(fsp->fsp_name);
		gh_spoolf("close\n%s\n%d\n%s\n\n",
			lp_servicename(talloc_tos(), lp_sub, handle->conn->params->service),
			fsp->fh->fd,
			fname);
	}

	return result;
}

// Ref: https://github.com/samba-team/samba/commit/4d74ed6f567832a43dbf04a868efe0f30cb53752#diff-b89f66a62fdd7038670ec2b19bba45a9
static int greyhole_renameat(vfs_handle_struct *handle, files_struct *srcfsp, const struct smb_filename *oldname, files_struct *dstfsp, const struct smb_filename *newname)
{
	int result;
	const struct loadparm_substitution *lp_sub = loadparm_s3_global_substitution();

	result = SMB_VFS_NEXT_RENAMEAT(handle, srcfsp, oldname, dstfsp, newname);

	if (result >= 0) {
		gh_spoolf("rename\n%s\n%s\n%s\n\n",
			lp_servicename(talloc_tos(), lp_sub, handle->conn->params->service),
			oldname->base_name,
			newname->base_name);
	}

	return result;
}

static int greyhole_linkat(vfs_handle_struct *handle, files_struct *srcfsp, const struct smb_filename *oldname, files_struct *dstfsp, const struct smb_filename *newname, int flags)
{
	int result;
	const struct loadparm_substitution *lp_sub = loadparm_s3_global_substitution();

	result = SMB_VFS_NEXT_LINKAT(handle, srcfsp, oldname, dstfsp, newname, flags);

	if (result >= 0) {
		gh_spoolf("link\n%s\n%s\n%s\n\n",
			lp_servicename(talloc_tos(), lp_sub, handle->conn->params->service),
			oldname->base_name,
			newname->base_name);
	}

	return result;
}

static int greyhole_unlinkat(vfs_handle_struct *handle, struct files_struct *dirfsp, const struct smb_filename *smb_fname, int flags)
{
	int result;
	const struct loadparm_substitution *lp_sub = loadparm_s3_global_substitution();

	result = SMB_VFS_NEXT_UNLINKAT(handle, dirfsp, smb_fname, flags);

	if (result >= 0) {
		if (flags & AT_REMOVEDIR) {
			gh_spoolf("rmdir\n%s\n%s\n\n",
				lp_servicename(talloc_tos(), lp_sub, handle->conn->params->service),
				smb_fname->base_name);
		} else {
			gh_spoolf("unlink\n%s\n%s\n\n",
				lp_servicename(talloc_tos(), lp_sub, handle->conn->params->service),
				smb_fname->base_name);
		}
	}

	return result;
}

NTSTATUS vfs_greyhole_init(TALLOC_CTX *ctx);
NTSTATUS vfs_greyhole_init(TALLOC_CTX *ctx)
{
	NTSTATUS ret = smb_register_vfs(SMB_VFS_INTERFACE_VERSION, "greyhole", &vfs_greyhole_fns);

	if (!NT_STATUS_IS_OK(ret))
		return ret;

	vfs_greyhole_debug_level = debug_add_class("greyhole");
	if (vfs_greyhole_debug_level == -1) {
		vfs_greyhole_debug_level = DBGC_VFS;
		DEBUG(0, ("vfs_greyhole: Couldn't register custom debugging class!\n"));
	} else {
		DEBUG(10, ("vfs_greyhole: Debug class number of 'greyhole': %d\n", vfs_greyhole_debug_level));
	}

	return ret;
}
