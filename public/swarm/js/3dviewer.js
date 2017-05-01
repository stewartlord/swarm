(function($, THREE) {
swarm.threejs = {
    dependencies: [
        '/vendor/threejs/three.min.js',
        '/vendor/threejs/EditorControls.js'
    ],
    supported: !!(window.CanvasRenderingContext2D && window.Float32Array),
    webgl: (function() {
        try {
            var canvas = document.createElement('canvas');
            return !!window.WebGLRenderingContext
                && (canvas.getContext('webgl') || canvas.getContext('experimental-webgl'));
        } catch(e) {
            return false;
        }
    }()),

    start: function() {
        // determine any file specific dependencies we need added
        var viewers = $('.view.threejs').each(function() {
            var loader = swarm.threejs.loaders[$(this).data('ext')];
            if (loader.dependencies) {
                swarm.threejs.dependencies.push.apply(swarm.threejs.dependencies, loader.dependencies);
                loader.dependencies = [];
            }
        });

        // go through each threejs element on the page and attempt to start rendering
        viewers.each(function() {
            // display error if canvas isn't supported
            if (!swarm.threejs.supported) {
                $(this).text(swarm.t("Model Viewer isn't supported in your browser."));
                return;
            }

            // load the dependencies if needed
            if (swarm.threejs.loading || swarm.threejs.dependencies.length) {
                swarm.threejs.showProgress(this);
                return swarm.threejs.loading || swarm.threejs.load();
            }

            // init this viewer if it has not already been started
            if (!$.data(this, 'started')) {
                // if we already have a canvas, clear everything
                if ($(this).find('canvas').length) {
                    $(this).empty();
                }
                swarm.threejs.init(this);
            }
        });
    },

    load: function() {
        swarm.threejs.loading = true;

        // once all dependencies are loaded, we can start the viewer again
        if (!swarm.threejs.dependencies.length) {
            swarm.threejs._firstScript = null;
            swarm.threejs.loading      = false;
            THREE                      = window.THREE;
            swarm.threejs.start();
            return;
        }

        // track the first script we find on the page, so we can add ourselves before it
        swarm.threejs._firstScript = swarm.threejs._firstScript || document.getElementsByTagName('script')[0];

        // add the dependency
        var script  = document.createElement('script');
        script.type = 'text/javascript';
        script.src  = swarm.threejs.dependencies.shift();
        $(script).one('load', function() {
            swarm.threejs.load();
        });
        swarm.threejs._firstScript.parentNode.insertBefore(script, swarm.threejs._firstScript);
    },

    showProgress: function(container, percent) {
        container       = $(container);
        percent         = percent || 0;
        var lastPercent = container.data('percent-loaded') || -1;

        if (parseInt(lastPercent, 10) === parseInt(percent, 10)) {
            return;
        }

        container.data('percent-loaded', percent);

        // update the progress bar width
        // but if the progress container doesn't exists, and the
        // percent isn't already 100, we should create it
        var progressBar = container.find('.progress-container .bar').css('width', percent + '%');
        if (!progressBar.length && parseInt(percent, 10) !== 100) {
            container.append(
                  '<div class="progress-container">'
                +   '<div class="progress">'
                +     '<div class="bar" style="width: ' + percent + '%;"></div>'
                +   '</div>'
                + '</div>'
            );
        }
    },

    showError: function(container, message) {
        $(container).find('.viewer-error').remove();
        $('<div class="viewer-error" />').appendTo(container).text(message);
    },

    init: function(container) {
        container = $(container);
        container.data('started', true);

        // find the correct json loader for this filetype
        var loader = swarm.threejs.loaders[container.data('ext')];

        // object for tracking everything that makes up the model viewer
        var viewer = {
            container: container[0],
            camera:    null,
            light:     null,
            scene:     null,
            renderer:  null,
            controls:  null,
            model:     null,
            options:   {wireframeControl: false}
        };

        // load the model file referenced in the container
        loader.load(container, function(model, options) {
            viewer.model = model;
            $.extend(viewer.options, options);

            swarm.threejs.buildScene(viewer);
            container.find('.progress-container').remove();
            swarm.threejs.animate(viewer);
        });
    },

    buildScene: function(viewer) {
        var container = $(viewer.container),
            width     = container.width(),
            height    = container.height();

        // create a new scene and camera for our viewer
        viewer.scene  = new THREE.Scene();
        // 45 degree vertical viewing area, width/height aspect ration,
        // and render units between 0.1 and 2000 (field of view)
        viewer.camera = new THREE.PerspectiveCamera(45, width / height, 0.1, 2000);
        // place the camera 14 units back, and 7 units up
        viewer.camera.position.set(0, 7, 14);

        // Build a line Grid to help show the 3 dimensional space
        var i, size  = 14,
            geometry = new THREE.Geometry(),
            material = new THREE.LineBasicMaterial({color: 0x555555});
        for (i = -size; i <= size; i += 1) {
            geometry.vertices.push(new THREE.Vector3(-size, -0.04, i));
            geometry.vertices.push(new THREE.Vector3(size,  -0.04, i));
            geometry.vertices.push(new THREE.Vector3(i,     -0.04, -size));
            geometry.vertices.push(new THREE.Vector3(i,     -0.04, size));
        }

        // add the grid to the scene
        var line = new THREE.Line(geometry, material, THREE.LinePieces);
        viewer.scene.add(line);

        // add the model to the scene
        viewer.scene.add(viewer.model);
        var modelBox = new THREE.Box3();
        modelBox.setFromObject(viewer.model);
        viewer.camera.lookAt(modelBox.center());

        // Add the listeners for allowing the user to control the model
        viewer.controls = new THREE.EditorControls(viewer.camera, viewer.container);
        viewer.controls.center.copy(modelBox.center());
        viewer.controls.enabled = false;

        // LIGHTING - can't see anything in the dark

        // Add ambient lights so we can see the objects
        viewer.scene.add(new THREE.AmbientLight(0x9F9F9F));

        // Add direction light that follows the camera in order to expose surface reflection colors,
        // this is important because some textures only have reflective colors
        viewer.light          = new THREE.PointLight(0xeeeeee, 1);
        viewer.light.position = viewer.camera.position;
        viewer.light.scale    = viewer.camera.scale;
        viewer.scene.add(viewer.light);

        // build the renderer
        viewer.renderer = swarm.threejs.webgl ? new THREE.WebGLRenderer() : new THREE.CanvasRenderer();
        viewer.renderer.setSize(width, height);
        viewer.renderer.setClearColor(0x777777, 1);
        container.append(viewer.renderer.domElement);

        // display notice if webgl not available
        if (!swarm.threejs.webgl) {
            container.append(
                  '<div class="viewer-notice">'
                +   swarm.te("WebGL was not available, so rendering may be slow.")
                + '</div>'
            );
        }

        // add the control buttons
        container.append(swarm.threejs.getControls(viewer));

        // adjust sizing of camera and rendered on window resize
        $(window).on('resize', function() {
            var width     = container.width(),
                height    = container.height();
            viewer.camera.aspect = width / height;

            viewer.camera.updateProjectionMatrix();
            viewer.light.scale = viewer.camera.scale;
            viewer.renderer.setSize(width, height);
        });
    },

    animate: function(viewer) {
        // request animation frame makes sure our tab is visible before running the next render
        window.requestAnimationFrame($.proxy(swarm.threejs.animate, swarm.threejs, viewer));

        // if track controls are disabled and we have webgl available we will rotate the camera
        if (!viewer.controls.enabled && swarm.threejs.webgl) {
            // use timestamp to represent change,
            // multiply to set speed - larger numbers result in faster rotation
            var timer = Date.now() * 0.0005;

            // set camera and light position by converting the timestamp to
            // an angle from center, then moving them 14 units away from the center
            viewer.camera.position.set(Math.cos(timer) * -14, 7, Math.sin(timer) * -14);
            viewer.light.position = viewer.camera.position;
            var modelBox = new THREE.Box3();
            modelBox.setFromObject(viewer.model);
            viewer.camera.lookAt(modelBox.center());
        }

        viewer.renderer.render(viewer.scene, viewer.camera);
    },

    getControls: function(viewer) {
        var controlTemplate = $.templates(
            '<div class="viewer-control {{:cls}} pad1" title="{{:title}}"><i class="icon icon-white {{:icon}}" /></div>'
        );

        var area  = $('<div class="viewer-controls" />');

        // add button for controlling the camera
        var track = {title1: swarm.t('Reset Camera'), title2: swarm.t('Control Camera')};
        $(controlTemplate.render(
            {cls:'track', icon: 'icon-camera', title: (viewer.controls.enabled ? track.title1 : track.title2)}
        )).on('click', function() {
            viewer.controls.enabled = !viewer.controls.enabled;

            if (viewer.controls.enabled) {
                var modelBox = new THREE.Box3();
                modelBox.setFromObject(viewer.model);
                viewer.controls.center.copy(modelBox.center());
            }

            $(this)
                .toggleClass('active', viewer.controls.enabled)
                .tooltip('destroy')
                .attr('title', viewer.controls.enabled ? track.title1 : track.title2)
                .trigger('mouseenter');
        }).appendTo(area);

        // add button for toggling wireframe rendering
        if (viewer.options.wireframeControl) {
            var wireframe = {title1: swarm.t('Show Material'), title2: swarm.t('Show Wireframe')};
            $(controlTemplate.render(
                {cls: 'wireframe', icon: 'icon-th', title: wireframe.title2}
            )).on('click', function() {
                var enabled = $(this).hasClass('active');
                $(this)
                    .toggleClass('active', !enabled)
                    .tooltip('destroy')
                    .attr('title', enabled ? wireframe.title2 : wireframe.title1)
                    .trigger('mouseenter');
                viewer.model.traverse(function(child) {
                    if (child instanceof THREE.Mesh) {
                        child.material.wireframe = !enabled;
                    }
                });
                viewer.model.updateMatrix();
            }).appendTo(area);
        }

        return area;
    },

    loaders: {
        _handleProgress: function(container, data) {
            var loaded   = parseInt(data.loaded, 10) * 100,
                total    = parseInt(data.total, 10);
            swarm.threejs.showProgress(container, Math.round(loaded / (total || 100)));
        },

        _LoadMultiMesh: function(container, object, callback, center) {
            // the object is only allowed to take up 90% of the size (before camera adjusts)
            var targetHeight = container.height() * 0.9,
                targetWidth  = container.width()  * 0.9;

            // use a box to calculate the object boundaries
            var box = new THREE.Box3();
            box.setFromObject(object);

            // determine how we should scale the object
            var max         = object.worldToLocal(box.max),
                min         = object.worldToLocal(box.min),
                scaleHeight = (targetHeight / (max.y - min.y)) / 100,
                scaleWidth  = (targetWidth  / (max.x - min.x)) / 100,
                scale       = Math.min(scaleHeight, scaleWidth);

            // scale the object
            object.scale.set(scale, scale, scale);
            object.updateMatrix();

            // use a box calculated using the new size
            box.setFromObject(object);

            // center the object in the scene
            object.position = box.center().negate();

            // if we don't want to keep it centered, position the object above the grid
            if (!center) {
                object.position.y = object.position.y + (box.center().y - box.min.y);
            }

            object.updateMatrix();
            callback(object);
        },

        dae: {
            dependencies: ['/vendor/threejs/ColladaLoader.js'],
            load: function(container, callback) {
                var loader = new THREE.ColladaLoader();
                loader.options.convertUpAxis = true;
                try {
                    loader.load(container.data('url'), function (collada) {
                        swarm.threejs.loaders._LoadMultiMesh(container, collada.scene, callback);
                    }, function(data) {
                        swarm.threejs.loaders._handleProgress(container, data);
                    });
                } catch (error) {
                    swarm.threejs.showError(container, swarm.t('Encountered an error while loading the model.'));
                }

                return loader;
            }
        },

        stl: {
            dependencies: ['/vendor/threejs/STLLoader.js'],
            load: function(container, callback) {
                var loader = new THREE.STLLoader();
                loader.addEventListener('progress', function(data) {
                    swarm.threejs.loaders._handleProgress(container, data);
                });
                loader.addEventListener('error', function() {
                    swarm.threejs.showError(container, swarm.t('Encountered an error while loading the model.'));
                });

                try {
                    loader.load(container.data('url'), function (stl) {
                        stl.computeBoundingBox();

                        var targetHeight = container.height() * 0.9,
                            targetWidth  = container.width()  * 0.9,
                            sizeX        = stl.boundingBox.max.x - stl.boundingBox.min.x,
                            sizeY        = stl.boundingBox.max.y - stl.boundingBox.min.y,
                            scaleHeight  = targetHeight / sizeY / 100,
                            scaleWidth   = targetWidth  / sizeX / 100,
                            scale        = Math.min(scaleHeight, scaleWidth),
                            object       = new THREE.Object3D();

                        object.add(new THREE.Mesh(stl, new THREE.MeshPhongMaterial({wireframe: false, color: 0x313131})));
                        object.position = new THREE.Vector3(0, 0, 0);
                        object.scale    = new THREE.Vector3(scale, scale, scale);
                        object.updateMatrix();

                        callback(object, {wireframeControl: true});
                    });
                } catch (error) {
                    swarm.threejs.showError(container, swarm.t('Encountered an error while loading the model.'));
                }

                return loader;
            }
        },

        obj: {
            dependencies: [
                '/vendor/threejs/MTLLoader.js',
                '/vendor/threejs/OBJMTLLoader.js'
            ],
            load: function(container, callback) {
                var loader = new THREE.OBJMTLLoader();

                try {
                    loader.load(container.data('url'), function (object) {
                        swarm.threejs.loaders._LoadMultiMesh(container, object, callback);
                    }, function(data) {
                        swarm.threejs.loaders._handleProgress(container, data);
                    }, function() {
                        swarm.threejs.showError(container, swarm.t('Encountered an error while loading the model.'));
                    });
                } catch (error) {
                    swarm.threejs.showError(container, swarm.t('Encountered an error while loading the model.'));
                }

                return loader;
            }
        }
    }
};
}(window.jQuery, window.THREE));