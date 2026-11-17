<?php

namespace ChristianHnl\AiDebugger\Services\Mods;

/**
 * Built-in knowledge about mods that are commonly part of client modpacks but do not belong on a server.
 * Used as a hint for the AI, Modrinth data (server_side = unsupported) is more reliable.
 */
class KnownMods
{
    /**
     * Mod ids (from the jar metadata) of client-only mods. Value = why.
     *
     * @var array<string, string>
     */
    public const CLIENT_ONLY = [
        // Rendering / performance (crash dedicated servers)
        'oculus' => 'shader loader (client rendering)',
        'iris' => 'shader loader (client rendering)',
        'sodium' => 'renderer (client only)',
        'embeddium' => 'renderer (client only)',
        'rubidium' => 'renderer (client only)',
        'magnesium' => 'renderer (client only)',
        'optifine' => 'renderer (client only)',
        'sodium_extra' => 'sodium addon (client only)',
        'sodiumextra' => 'sodium addon (client only)',
        'reeses_sodium_options' => 'sodium addon (client only)',
        'sodiumoptionsapi' => 'sodium addon (client only)',
        'sodiumdynamiclights' => 'dynamic lights (client only)',
        'embeddiumplus' => 'embeddium addon (client only)',
        'embeddium_extra' => 'embeddium addon (client only)',
        'textrues_embeddium_options' => 'embeddium addon (client only)',
        'nvidium' => 'renderer (client only)',
        'immediatelyfast' => 'rendering optimization (client only)',
        'entityculling' => 'rendering optimization (client only)',
        'moreculling' => 'rendering optimization (client only)',
        'cullleaves' => 'rendering optimization (client only)',
        'cull_less_leaves' => 'rendering optimization (client only)',
        'enhancedblockentities' => 'rendering optimization (client only)',
        'betterbiomeblend' => 'rendering (client only)',
        'continuity' => 'connected textures (client only)',
        'entity_texture_features' => 'texture features (client only)',
        'entity_model_features' => 'model features (client only)',
        'citresewn' => 'resource pack features (client only)',
        'cit_resewn' => 'resource pack features (client only)',
        'dynamiclights' => 'dynamic lights (client only)',
        'lambdynlights' => 'dynamic lights (client only)',
        'dynamiclightsreforged' => 'dynamic lights (client only)',
        'irisflw' => 'shader compat (client only)',
        'oculusflywheelcompat' => 'shader compat (client only)',
        'euphoria_patcher' => 'shader patcher (client only)',
        'flerovium' => 'rendering optimization (client only)',
        'chunkanimator' => 'chunk animation (client only)',
        'fpsreducer' => 'fps limiter (client only)',
        'dynamic_fps' => 'fps limiter (client only)',
        'dynamicfps' => 'fps limiter (client only)',
        'smoothboot' => 'startup tweak (client only)',
        // Visual / UI
        'notenoughanimations' => 'animations (client only)',
        'firstperson' => 'first person model (client only)',
        'firstpersonmod' => 'first person model (client only)',
        'skinlayers3d' => '3d skin layers (client only)',
        '3dskinlayers' => '3d skin layers (client only)',
        'betterf3' => 'debug screen (client only)',
        'fancymenu' => 'menu customization (client only)',
        'drippyloadingscreen' => 'loading screen (client only)',
        'loadmyresources' => 'resource loading (client only)',
        'legendarytooltips' => 'tooltips (client only)',
        'equipmentcompare' => 'tooltips (client only)',
        'enchdesc' => 'enchantment descriptions (client only)',
        'enchantmentdescriptions' => 'enchantment descriptions (client only)',
        'toastcontrol' => 'toasts (client only)',
        'controlling' => 'keybind search (client only)',
        'mousetweaks' => 'inventory mouse tweaks (client only)',
        'catalogue' => 'mod list (client only)',
        'modmenu' => 'mod list (client only)',
        'betteradvancements' => 'advancement screen (client only)',
        'paginatedadvancements' => 'advancement screen (client only)',
        'neat' => 'health bars (client only)',
        'torohealth' => 'health bars (client only)',
        'lootbeams' => 'loot beams (client only)',
        'visuality' => 'particles (client only)',
        'particlerain' => 'weather particles (client only)',
        'fallingleaves' => 'leaf particles (client only)',
        'betterclouds' => 'clouds (client only)',
        'zoomify' => 'zoom (client only)',
        'okzoomer' => 'zoom (client only)',
        'ok_zoomer' => 'zoom (client only)',
        'logical_zoom' => 'zoom (client only)',
        'wi_zoom' => 'zoom (client only)',
        'shouldersurfing' => 'camera (client only)',
        'betterthirdperson' => 'camera (client only)',
        'freecam' => 'camera (client only)',
        'cherishedworlds' => 'world list (client only)',
        'chat_heads' => 'chat (client only)',
        'chatheads' => 'chat (client only)',
        'blur' => 'menu blur (client only)',
        'colorwheel' => 'shader colors (client only)',
        'resourcepackoverrides' => 'resource packs (client only)',
        'defaultoptions' => 'default client options (client only)',
        'rrls' => 'loading screen (client only)',
        'durabilitytooltip' => 'tooltips (client only)',
        'appleskin_client' => 'hud (client only)',
        'xaerominimap' => 'minimap (client only)',
        'xaeroworldmap' => 'world map (client only)',
        'xaerobetterpvp' => 'minimap (client only)',
        'minihud' => 'hud (client only)',
        'tweakeroo' => 'client tweaks (client only)',
        'litematica' => 'schematics (client only)',
        'itemscroller' => 'inventory (client only)',
        'mousewheelie' => 'inventory (client only)',
        'inventoryprofilesnext' => 'inventory (client only)',
        'libipn' => 'inventory library (client only)',
        'replaymod' => 'recording (client only)',
        'craftpresence' => 'discord presence (client only)',
        'simplerpc' => 'discord presence (client only)',
        'discordrpc' => 'discord presence (client only)',
        'reauth' => 'login (client only)',
        // Sound
        'ambientsounds' => 'ambient sounds (client only)',
        'sound_physics_remastered' => 'sound engine (client only)',
        'soundphysics' => 'sound engine (client only)',
        'presencefootsteps' => 'footstep sounds (client only)',
        'dynamicsurroundings' => 'sounds/effects (client only)',
        'extremesoundmuffler' => 'sound muffler (client only)',
    ];

    /**
     * Words in jar file names that strongly suggest a client-only mod.
     *
     * @var string[]
     */
    public const CLIENT_FILENAME_PATTERNS = [
        'oculus', 'iris-', 'sodium', 'embeddium', 'rubidium', 'optifine', 'entityculling', 'entity_texture_features',
        'entity_model_features', 'notenoughanimations', 'fancymenu', 'drippyloading', 'skinlayers', 'betterf3',
        'continuity', 'ambientsounds', 'soundphysics', 'sound-physics', 'immediatelyfast', 'xaeros_minimap', 'xaerominimap',
        'xaeroworldmap', 'xaeros_world_map', 'legendarytooltips', 'toastcontrol', 'controlling', 'mousetweaks',
        'dynamiclights', 'lambdynamiclights', 'fpsreducer', 'dynamic-fps', 'zoomify', 'replaymod', 'shouldersurfing',
    ];

    public static function clientOnlyReason(string $modId): ?string
    {
        return self::CLIENT_ONLY[strtolower($modId)] ?? null;
    }

    public static function clientFilenameHint(string $filename): ?string
    {
        $filename = strtolower($filename);

        foreach (self::CLIENT_FILENAME_PATTERNS as $pattern) {
            if (str_contains($filename, $pattern)) {
                return "file name contains '$pattern'";
            }
        }

        return null;
    }
}
