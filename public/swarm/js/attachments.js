(function($) {
    var Uploader = function(options) {
        $.extend(this, Uploader.defaults, options);

        this.getControls();
        if (this.isExistingFile()) {
            this.rebuildControls();
        } else {
            this.upload();
        }
        this.onStart($.Event('start', { target: this }));
    };

    Uploader.defaults = {
        file:              null,
        maxSize:           null,
        uploadUrl:         null,
        controlsContainer: null,
        extraData:         null,
        onStart:           $.noop, // optional
        onRemove:          $.noop  // optional
    };

    Uploader.prototype = {
        uploaded: null,
        response: null,
        controls: null,

        isExistingFile: function() {
            return !(this.file instanceof window.File);
        },

        isRemoved: function() {
            return (this.controls && this.controls.find('input').prop('disabled')) ? true : false;
        },

        upload: function() {
            this.uploaded = 0;

            if (this.file.size > this.maxSize) {
                var friendlyMaxSize = function(size) {
                    if (size < 1024) {
                        return size + 'B';
                    }

                    if (size < Math.pow(1024,2)) {
                        return Math.round(size / 1024) + 'KB';
                    }

                    return Math.round(size / Math.pow(1024,2)) + 'MB';
                };

                return this.errorBar(
                    this.getControls(),
                    swarm.te('File too large') + ' (>' + friendlyMaxSize(this.maxSize) + ')'
                );
            }

            var form = new FormData();
            form.append('file', this.file);
            form.append('name', this.file.name);

            // append any additional data
            $.each(this.extraData || {}, function(key, value) {
                form.append(key, value);
            });

            // setup http post request
            // - track upload progress
            // - track when file uploads complete
            // - if an error occurs (i.e. folder upload is attempted) display an error message
            var xhr = new XMLHttpRequest();
            this.attachProgressHandler(xhr);
            this.attachLoadHandler(xhr);
            this.attachErrorHandler(xhr);

            xhr.open('POST', this.uploadUrl);
            xhr.send(form);
        },

        attachErrorHandler: function(xhr) {
            xhr.addEventListener('error', $.proxy(function(e) {
                var response = e.currentTarget;

                // we've encountered an error and may not get a response from the server; here we ensure
                // the error message gets re-created if we have to re-create the controls
                response.isValid = false;
                this.response    = response;
                this.updateProgress();
            }, this));
        },

        attachProgressHandler: function(xhr) {
            xhr.upload.addEventListener('progress', $.proxy(function(e) {
                if (e.lengthComputable) {
                    this.uploaded = e.loaded;
                }
                this.updateProgress();
            }, this));
        },

        attachLoadHandler: function(xhr) {
            xhr.addEventListener('load', $.proxy(function(e) {
                var response    = this.response = e.currentTarget,
                    controls    = this.getControls();

                // try and parse the response
                try {
                    response.json    = $.parseJSON(response.responseText);
                    response.isValid = response.status === 200 && response.json.isValid;
                } catch(err) {
                    response.isValid = false;
                }

                // if response was valid, update the size and add attachment to hidden attachments field
                if (response.isValid) {
                    this.uploaded = this.file.size;
                    controls.append('<input type="hidden" name="attachments[]" value=\'' + response.json.attachment.id + '\'>');
                }

                this.updateProgress();
            }, this));
        },

        updateProgress: function() {
            var percent  = this.isExistingFile() ? 100 : Math.round(this.uploaded / this.file.size) * 100;
            var controls = this.getControls();
            controls.find('.bar').css('width', percent + '%');

            // if we have a response, but it is invalid, show the errorBar
            // otherwise if we have a response, mark as success
            if (this.response && !this.response.isValid) {
                this.errorBar(controls, swarm.te('Upload Failed'));
            } else if (this.response || this.isExistingFile()) {
                controls.find('.progress').removeClass('active progress-striped').addClass('progress-success');
                controls.find('.bar').css('width', '100%');
                controls.removeClass('invalid');
                swarm.form.checkInvalid(controls.closest('form'));
            }
        },

        getControls: function(){
            if (this.controls) {
                return this.controls;
            }

            this.controls = $($.templates(
                '<div class="upload-controls pad1 invalid">'
              +  '<span class="close" title="{{te:"Remove"}}"><i class="icon-remove"></i></span>'
              +  '<div class="progress active">'
              +   '<div class="bar underlay">{{>file.name}}</div>'
              +   '<div class="bar">{{>file.name}}</div>'
              +  '</div>'
              + '</div>'
            ).render({file: this.file}));

            this.controlsContainer.append(this.controls);

            // on click of remove/restore button, disable/enable this file's input and check if the form is valid
            this.controls.find('.close').on('click', $.proxy(function(e) {
                var button = this.controls.find('.close'),
                    input  = this.controls.find('input'),
                    icon   = this.controls.find('.close i');

                input.prop('disabled', !input.prop('disabled'));

                if (input.prop('disabled')) {
                    this.controls.addClass('removed');
                    button.attr('data-original-title', swarm.te('Restore'));
                    icon.removeClass('icon-remove').addClass('icon-share-alt');
                } else {
                    this.controls.removeClass('removed');
                    button.attr('data-original-title', swarm.te('Remove'));
                    icon.removeClass('icon-share-alt').addClass('icon-remove');
                }
                button.tooltip('show');

                swarm.form.checkInvalid(this.controlsContainer.closest('form'));

                this.onRemove($.Event('remove', {target: this}));
            }, this));

            // the form is rendered invalid by default until all uploads have completed
            swarm.form.checkInvalid(this.controlsContainer.closest('form'));

            return this.controls;
        },

        rebuildControls: function(container) {
            var removed = this.isRemoved();
            $(this.controls).remove();

            this.controls          = null;
            this.controlsContainer = container ? $(container) : this.controlsContainer;
            var controls           = this.getControls();

            // if we have an existing file or a valid response, add attachments field
            if (this.isExistingFile() || (this.response && this.response.isValid)) {
                var id    = this.isExistingFile() ? this.file.id : this.response.json.attachment.id,
                    input = $('<input>').attr('type', 'hidden').attr('name', 'attachments[]').attr('value', id);
                controls.append(input);

                if (removed) {
                    input.prop('disabled', true);
                    controls.addClass('removed');
                    controls.find('.close').attr('title', swarm.te('Restore'));
                    controls.find('.close i').removeClass('icon-remove').addClass('icon-share-alt');
                }
            }

            this.updateProgress();
        },

        errorBar: function(controls, msg) {
            var progressBar = controls.find('.progress');
            var displayBar  = controls.find('.bar');

            progressBar.removeClass('active progress-striped');
            progressBar.addClass('progress-danger');

            displayBar.append(' - ' + msg);
            displayBar.css('width', '100%');
        }
    };

    var DropZone = function(element, options) {
        this.$element           = $(element);
        this.options            = $.extend({}, $.fn.dropZone.defaults, options);
        this.$controlsContainer = this.$element.find('.drop-controls');
        this.uploaders          = [];

        // prevent normal browser behavior when dragging over drop zone.
        this.$element.on('dragover dragenter', function(e) {
            e.preventDefault(); e.stopPropagation();
            $(element).addClass('dragover');
        });

        // make element style-able when dragging/dropping
        this.$element.on('dragleave', function(e) {
            $(element).removeClass('dragover');
        });

        // if user drops file(s), process them.
        this.$element.on('drop', $.proxy(this.onDrop, this));
    };

    DropZone.prototype = {
        constructor: DropZone,

        onDrop: function(e) {
            e.preventDefault();
            e.stopPropagation();

            this.$element.removeClass('dragover');

            var i, files = e.originalEvent.dataTransfer.files;
            for (i = 0; i < files.length; i++) {
                this.addUploader(files[i]);
            }
        },

        addUploader: function(uploader) {
            // uploader can be an Uploader instance, a File instance, or an object with file info
            if (uploader instanceof Uploader) {
                uploader.controlsContainer = this.$controlsContainer;
                uploader.rebuildControls();
            } else {
                uploader = new Uploader($.extend({
                    file:              uploader,
                    maxSize:           this.$element.closest('form').data('max-size'),
                    controlsContainer: this.$controlsContainer,
                    uploadUrl:         this.$element.data('upload-url')
                }, this.options.uploaderOptions));
            }

            this.uploaders.push(uploader);
        },

        addExistingFile: function (file) {
            this.addUploader(file);
        }
    };

    $.fn.dropZone = function(options) {
        return this.each(function() {
            var $this    = $(this),
                dropZone = $this.data('dropZone');

            // don't setup twice or if browser doesn't support dnd uploads.
            if (dropZone || !swarm.has.fullFileApi()) {
                return;
            }

            $this.data('dropZone', new DropZone(this, options));
        });
    };

    $.fn.dropZone.Constructor = DropZone;
    $.fn.dropZone.defaults    = {
        uploaderOptions: null
    };
}(window.jQuery));
