/* 
Copyright 2009-2014 Guillaume Boudreau

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

static int vfs_greyhole_debug_level = DBGC_VFS;

#undef DBGC_CLASS
#define DBGC_CLASS vfs_greyhole_debug_level

#define vfs_greyhole_init init_samba_module

/* Function prototypes */

static int greyhole_connect(vfs_handle_struct *handle, const char *svc, const char *user);
static int greyhole_mkdir(vfs_handle_struct *handle, const char *path, mode_t mode);
static int greyhole_rmdir(vfs_handle_struct *handle, const char *path);
static int greyhole_open(vfs_handle_struct *handle, struct smb_filename *fname, files_struct *fsp, int flags, mode_t mode);
static ssize_t greyhole_write(vfs_handle_struct *handle, files_struct *fsp, const void *data, size_t count);
static ssize_t greyhole_pwrite(vfs_handle_struct *handle, files_struct *fsp, const void *data, size_t count, SMB_OFF_T offset);
static int greyhole_close(vfs_handle_struct *handle, files_struct *fsp);
static int greyhole_rename(vfs_handle_struct *handle, const struct smb_filename *oldname, const struct smb_filename *newname);
static int greyhole_unlink(vfs_handle_struct *handle, const struct smb_filename *path);

/* VFS operations */

static struct vfs_fn_pointers vfs_greyhole_fns = {
	
	/* Disk operations */
	
	.connect_fn = greyhole_connect,
	
	/* Directory operations */
	
	.mkdir = greyhole_mkdir,
	.rmdir = greyhole_rmdir,
	
	/* File operations */
	
	.open = greyhole_open,
	.write = greyhole_write,
	.pwrite = greyhole_pwrite,
	.close_fn = greyhole_close,
	.rename = greyhole_rename,
	.unlink = greyhole_unlink
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

static int greyhole_syslog_facility(vfs_handle_struct *handle)
{
	static const struct enum_list enum_log_facilities[] = {
		{ LOG_USER, "USER" },
		{ LOG_LOCAL0, "LOCAL0" },
		{ LOG_LOCAL1, "LOCAL1" },
		{ LOG_LOCAL2, "LOCAL2" },
		{ LOG_LOCAL3, "LOCAL3" },
		{ LOG_LOCAL4, "LOCAL4" },
		{ LOG_LOCAL5, "LOCAL5" },
		{ LOG_LOCAL6, "LOCAL6" },
		{ LOG_LOCAL7, "LOCAL7" }
	};

	int facility;

	facility = lp_parm_enum(SNUM(handle->conn), "greyhole", "facility", enum_log_facilities, LOG_LOCAL6);

	return facility;
}

/* Implementation of vfs_ops.  Pass everything on to the default
   operation but log event first. */

static int greyhole_connect(vfs_handle_struct *handle, const char *svc, const char *user)
{
	int result;

	if (!handle) {
		return -1;
	}

	openlog("smbd_greyhole", 0, greyhole_syslog_facility(handle));

	result = SMB_VFS_NEXT_CONNECT(handle, svc, user);

	return result;
}

static int greyhole_mkdir(vfs_handle_struct *handle, const char *path, mode_t mode)
{
	int result;
	FILE *spoolf;
	char filename[38];
	struct timeval tp;

	result = SMB_VFS_NEXT_MKDIR(handle, path, mode);

	if (result >= 0) {
		gettimeofday(&tp, (struct timezone *) NULL);
		snprintf(filename, 37, "/var/spool/greyhole/%.0f", ((double) (tp.tv_sec)*1000000.0) + (((double) tp.tv_usec)));
		spoolf = fopen(filename, "wt");
		fprintf(spoolf, "mkdir\n%s\n%s\n\n",
			lp_servicename(handle->conn->params->service),
			path);
		fclose(spoolf);
	}

	return result;
}

static int greyhole_rmdir(vfs_handle_struct *handle, const char *path)
{
	int result;
	FILE *spoolf;
	char filename[38];
	struct timeval tp;

	result = SMB_VFS_NEXT_RMDIR(handle, path);

	if (result >= 0) {
		gettimeofday(&tp, (struct timezone *) NULL);
		snprintf(filename, 37, "/var/spool/greyhole/%.0f", ((double) (tp.tv_sec)*1000000.0) + (((double) tp.tv_usec)));
		spoolf = fopen(filename, "wt");
		fprintf(spoolf, "rmdir\n%s\n%s\n\n",
			lp_servicename(handle->conn->params->service),
			path);
		fclose(spoolf);
	}
	
	return result;
}

static int greyhole_open(vfs_handle_struct *handle, struct smb_filename *fname, files_struct *fsp, int flags, mode_t mode)
{
	int result;
	FILE *spoolf;
	char filename[38];
	struct timeval tp;

	result = SMB_VFS_NEXT_OPEN(handle, fname, fsp, flags, mode);

	if (result >= 0) {
		if ((flags & O_WRONLY) || (flags & O_RDWR)) {
			gettimeofday(&tp, (struct timezone *) NULL);
			snprintf(filename, 37, "/var/spool/greyhole/%.0f", ((double) (tp.tv_sec)*1000000.0) + (((double) tp.tv_usec)));
			spoolf = fopen(filename, "wt");
			fprintf(spoolf, "open\n%s\n%s\n%d\n%s\n",
				lp_servicename(handle->conn->params->service),
				fname->base_name,
				result,
				"for writing ");
			fclose(spoolf);
		}
	}

	return result;
}

static ssize_t greyhole_write(vfs_handle_struct *handle, files_struct *fsp, const void *data, size_t count)
{
	ssize_t result;
	FILE *spoolf;
	char filename[255];
	struct timeval tp;

	result = SMB_VFS_NEXT_WRITE(handle, fsp, data, count);

	if (result >= 0) {
		gettimeofday(&tp, (struct timezone *) NULL);
		char *share = lp_servicename(handle->conn->params->service);
		snprintf(filename, 39 + sizeof(share) + nDigits(fsp->fh->fd), "/var/spool/greyhole/%.0f-%s-%d", ((double) (tp.tv_sec)*1000000.0), share, fsp->fh->fd);
		spoolf = fopen(filename, "wt");
		fprintf(spoolf, "fwrite\n%s\n%d\n\n",
			share,
			fsp->fh->fd);
		fclose(spoolf);
	}

	return result;
}

static ssize_t greyhole_pwrite(vfs_handle_struct *handle, files_struct *fsp, const void *data, size_t count, SMB_OFF_T offset)
{
	ssize_t result;
	FILE *spoolf;
	char filename[255];
	struct timeval tp;

	result = SMB_VFS_NEXT_PWRITE(handle, fsp, data, count, offset);

	if (result >= 0) {
		gettimeofday(&tp, (struct timezone *) NULL);
		char *share = lp_servicename(handle->conn->params->service);
		snprintf(filename, 39 + sizeof(share) + nDigits(fsp->fh->fd), "/var/spool/greyhole/%.0f-%s-%d", ((double) (tp.tv_sec)*1000000.0), share, fsp->fh->fd);
		spoolf = fopen(filename, "wt");
		fprintf(spoolf, "fwrite\n%s\n%d\n\n",
			share,
			fsp->fh->fd);
		fclose(spoolf);
	}

	return result;
}

static int greyhole_close(vfs_handle_struct *handle, files_struct *fsp)
{
	int result;
	FILE *spoolf;
	char filename[38];
	struct timeval tp;

	result = SMB_VFS_NEXT_CLOSE(handle, fsp);

	if (result >= 0) {
		gettimeofday(&tp, (struct timezone *) NULL);
		snprintf(filename, 37, "/var/spool/greyhole/%.0f", ((double) (tp.tv_sec)*1000000.0) + (((double) tp.tv_usec)));
		spoolf = fopen(filename, "wt");
		fprintf(spoolf, "close\n%s\n%d\n\n",
			lp_servicename(handle->conn->params->service),
			fsp->fh->fd);
		fclose(spoolf);
	}

	return result;
}

static int greyhole_rename(vfs_handle_struct *handle, const struct smb_filename *oldname, const struct smb_filename *newname)
{
	int result;
	FILE *spoolf;
	char filename[38];
	struct timeval tp;

	result = SMB_VFS_NEXT_RENAME(handle, oldname, newname);

	if (result >= 0) {
		gettimeofday(&tp, (struct timezone *) NULL);
		snprintf(filename, 37, "/var/spool/greyhole/%.0f", ((double) (tp.tv_sec)*1000000.0) + (((double) tp.tv_usec)));
		spoolf = fopen(filename, "wt");
		fprintf(spoolf, "rename\n%s\n%s\n%s\n\n",
			lp_servicename(handle->conn->params->service),
			oldname->base_name,
			newname->base_name);
		fclose(spoolf);
	}

	return result;
}

static int greyhole_unlink(vfs_handle_struct *handle, const struct smb_filename *path)
{
	int result;
	FILE *spoolf;
	char filename[38];
	struct timeval tp;

	result = SMB_VFS_NEXT_UNLINK(handle, path);

	if (result >= 0) {
		gettimeofday(&tp, (struct timezone *) NULL);
		snprintf(filename, 37, "/var/spool/greyhole/%.0f", ((double) (tp.tv_sec)*1000000.0) + (((double) tp.tv_usec)));
		spoolf = fopen(filename, "wt");
		fprintf(spoolf, "unlink\n%s\n%s\n\n",
			lp_servicename(handle->conn->params->service),
			path->base_name);
		fclose(spoolf);
	}

	return result;
}

NTSTATUS vfs_greyhole_init(void);
NTSTATUS vfs_greyhole_init(void)
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
