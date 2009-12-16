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

static int greyhole_mkdir(vfs_handle_struct *handle, const char *path, mode_t mode);
static int greyhole_rmdir(vfs_handle_struct *handle, const char *path);
static int greyhole_open(vfs_handle_struct *handle, const char *fname, files_struct *fsp, int flags, mode_t mode);
static int greyhole_close(vfs_handle_struct *handle, files_struct *fsp);
static int greyhole_rename(vfs_handle_struct *handle, const char *oldname, const char *newname);
static int greyhole_unlink(vfs_handle_struct *handle, const char *path);

/* VFS operations */

static vfs_op_tuple greyhole_op_tuples[] = {
    
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

/* Implementation of vfs_ops.  Pass everything on to the default
   operation but log event first. */

static int greyhole_mkdir(vfs_handle_struct *handle, const char *path, mode_t mode)
{
	int result;

	result = SMB_VFS_NEXT_MKDIR(handle, path, mode);

	DEBUG(0, ("vfs_greyhole: mkdir|%s|%s|%s%s\n",
	       handle->param, path,
	       (result < 0) ? "failed: " : "",
	       (result < 0) ? strerror(errno) : ""));

	return result;
}

static int greyhole_rmdir(vfs_handle_struct *handle, const char *path)
{
	int result;

	result = SMB_VFS_NEXT_RMDIR(handle, path);

	DEBUG(0, ("vfs_greyhole: rmdir|%s|%s|%s%s\n",
               handle->param, path,
	       (result < 0) ? "failed: " : "",
	       (result < 0) ? strerror(errno) : ""));

	return result;
}

static int greyhole_open(vfs_handle_struct *handle, const char *fname, files_struct *fsp, int flags, mode_t mode)
{
	int result;

	result = SMB_VFS_NEXT_OPEN(handle, fname, fsp, flags, mode);

	if ((flags & O_WRONLY) || (flags & O_RDWR)) {
		DEBUG(0, ("vfs_greyhole: open|%s|%s|%d|%s%s%s\n",
		       handle->param, fname, result,
		       "for writing ",
	       (result < 0) ? "failed: " : "",
	       (result < 0) ? strerror(errno) : ""));
	}

	return result;
}

static int greyhole_close(vfs_handle_struct *handle, files_struct *fsp)
{
	int result;

	result = SMB_VFS_NEXT_CLOSE(handle, fsp);

	DEBUG(0, ("vfs_greyhole: close|%s|%d|%s%s\n",
	       handle->param, fsp->fh->fd,
	       (result < 0) ? "failed: " : "",
	       (result < 0) ? strerror(errno) : ""));

	return result;
}

static int greyhole_rename(vfs_handle_struct *handle, const char *oldname, const char *newname)
{
	int result;

	result = SMB_VFS_NEXT_RENAME(handle, oldname, newname);

	DEBUG(0, ("vfs_greyhole: rename|%s|%s|%s|%s%s\n",
	       handle->param, oldname, newname,
	       (result < 0) ? "failed: " : "",
	       (result < 0) ? strerror(errno) : ""));

	return result;
}

static int greyhole_unlink(vfs_handle_struct *handle, const char *path)
{
	int result;

	result = SMB_VFS_NEXT_UNLINK(handle, path);

	DEBUG(0, ("vfs_greyhole: unlink|%s|%s|%s%s\n",
	       handle->param, path,
	       (result < 0) ? "failed: " : "",
	       (result < 0) ? strerror(errno) : ""));

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
