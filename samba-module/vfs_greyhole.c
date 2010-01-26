/* 
Copyright Guillaume Boudreau, 2009

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
static int greyhole_open(vfs_handle_struct *handle, const char *fname, files_struct *fsp, int flags, mode_t mode);
static int greyhole_close(vfs_handle_struct *handle, files_struct *fsp);
static int greyhole_rename(vfs_handle_struct *handle, const char *oldname, const char *newname);
static int greyhole_unlink(vfs_handle_struct *handle, const char *path);

/* VFS operations */

static vfs_op_tuple greyhole_op_tuples[] = {

	/* Disk operations */

	{SMB_VFS_OP(greyhole_connect),		SMB_VFS_OP_CONNECT,	SMB_VFS_LAYER_LOGGER},
    
	/* Directory operations */

	{SMB_VFS_OP(greyhole_mkdir), 		SMB_VFS_OP_MKDIR, 	SMB_VFS_LAYER_LOGGER},
	{SMB_VFS_OP(greyhole_rmdir), 		SMB_VFS_OP_RMDIR, 	SMB_VFS_LAYER_LOGGER},

	/* File operations */

	{SMB_VFS_OP(greyhole_open), 		SMB_VFS_OP_OPEN, 	SMB_VFS_LAYER_LOGGER},
	{SMB_VFS_OP(greyhole_close), 		SMB_VFS_OP_CLOSE, 	SMB_VFS_LAYER_LOGGER},
	{SMB_VFS_OP(greyhole_rename), 		SMB_VFS_OP_RENAME, 	SMB_VFS_LAYER_LOGGER},
	{SMB_VFS_OP(greyhole_unlink), 		SMB_VFS_OP_UNLINK, 	SMB_VFS_LAYER_LOGGER},
	
	/* Finish VFS operations definition */
	
	{SMB_VFS_OP(NULL), 			SMB_VFS_OP_NOOP, 	SMB_VFS_LAYER_NOOP}
};

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

	result = SMB_VFS_NEXT_MKDIR(handle, path, mode);

	syslog(LOG_NOTICE, "mkdir*%s*%s*%s%s\n",
	       lp_servicename(handle->conn->params->service), path,
	       (result < 0) ? "failed: " : "",
	       (result < 0) ? strerror(errno) : "");

	return result;
}

static int greyhole_rmdir(vfs_handle_struct *handle, const char *path)
{
	int result;

	result = SMB_VFS_NEXT_RMDIR(handle, path);

	syslog(LOG_NOTICE, "rmdir*%s*%s*%s%s\n",
               lp_servicename(handle->conn->params->service), path,
	       (result < 0) ? "failed: " : "",
	       (result < 0) ? strerror(errno) : "");
	
	return result;
}

static int greyhole_open(vfs_handle_struct *handle, const char *fname, files_struct *fsp, int flags, mode_t mode)
{
	int result;

	result = SMB_VFS_NEXT_OPEN(handle, fname, fsp, flags, mode);

	if ((flags & O_WRONLY) || (flags & O_RDWR)) {
		syslog(LOG_NOTICE, "open*%s*%s*%d*%s%s%s\n",
		       lp_servicename(handle->conn->params->service), fname, result,
		       "for writing ",
	       (result < 0) ? "failed: " : "",
	       (result < 0) ? strerror(errno) : "");
	}

	return result;
}

static int greyhole_close(vfs_handle_struct *handle, files_struct *fsp)
{
	int result;

	result = SMB_VFS_NEXT_CLOSE(handle, fsp);

	syslog(LOG_NOTICE, "close*%s*%d*%s%s\n",
	       lp_servicename(handle->conn->params->service), fsp->fh->fd,
	       (result < 0) ? "failed: " : "",
	       (result < 0) ? strerror(errno) : "");

	return result;
}

static int greyhole_rename(vfs_handle_struct *handle, const char *oldname, const char *newname)
{
	int result;

	result = SMB_VFS_NEXT_RENAME(handle, oldname, newname);

	syslog(LOG_NOTICE, "rename*%s*%s*%s*%s%s\n",
	       lp_servicename(handle->conn->params->service), oldname, newname,
	       (result < 0) ? "failed: " : "",
	       (result < 0) ? strerror(errno) : "");

	return result;
}

static int greyhole_unlink(vfs_handle_struct *handle, const char *path)
{
	int result;

	result = SMB_VFS_NEXT_UNLINK(handle, path);

	syslog(LOG_NOTICE, "unlink*%s*%s*%s%s\n",
	       lp_servicename(handle->conn->params->service), path,
	       (result < 0) ? "failed: " : "",
	       (result < 0) ? strerror(errno) : "");

	return result;
}

NTSTATUS vfs_greyhole_init(void);
NTSTATUS vfs_greyhole_init(void)
{
	NTSTATUS ret = smb_register_vfs(SMB_VFS_INTERFACE_VERSION, "greyhole", greyhole_op_tuples);
	
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
