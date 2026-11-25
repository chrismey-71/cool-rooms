#!/usr/bin/env swift
// Run from the repository root on macOS: swift tools/render_icons.swift
import AppKit
import Foundation

let root = URL(fileURLWithPath: FileManager.default.currentDirectoryPath)
let source = root.appendingPathComponent("www/assets/cool-rooms-logo.svg")
let output = root.appendingPathComponent("www/assets/icons", isDirectory: true)
let svg = try String(contentsOf: source, encoding: .utf8)
let tile = "<rect width=\"128\" height=\"128\" rx=\"28\"/>"
guard svg.contains(tile) else { fatalError("Expected the rounded background clip in the source SVG") }
// iPhone and maskable icons use a full-bleed background; the OS supplies the mask.
let fullBleed = svg.replacingOccurrences(of: tile, with: "<rect width=\"128\" height=\"128\"/>")

func render(_ source: String, size: Int, opaque: Bool, to destination: URL) throws {
    guard let image = NSImage(data: Data(source.utf8)),
          let bitmap = NSBitmapImageRep(
            bitmapDataPlanes: nil, pixelsWide: size, pixelsHigh: size,
            bitsPerSample: 8, samplesPerPixel: opaque ? 3 : 4, hasAlpha: !opaque,
            isPlanar: false, colorSpaceName: .deviceRGB,
            bytesPerRow: size * 4, bitsPerPixel: 32),
          let context = NSGraphicsContext(bitmapImageRep: bitmap)
    else { fatalError("Cannot create icon renderer") }
    let bounds = NSRect(x: 0, y: 0, width: size, height: size)
    NSGraphicsContext.saveGraphicsState()
    NSGraphicsContext.current = context
    context.imageInterpolation = .high
    context.cgContext.setShouldAntialias(true)
    if opaque {
        NSColor(red: 23/255, green: 76/255, blue: 61/255, alpha: 1).setFill()
        bounds.fill()
    } else {
        context.cgContext.clear(bounds)
    }
    image.draw(in: bounds, from: .zero, operation: .sourceOver, fraction: 1)
    context.flushGraphics()
    NSGraphicsContext.restoreGraphicsState()
    guard let png = bitmap.representation(using: .png, properties: [:]) else { fatalError("PNG encoding failed") }
    try png.write(to: destination, options: .atomic)
    guard let check = NSBitmapImageRep(data: png), check.pixelsWide == size,
          check.pixelsHigh == size, check.hasAlpha == !opaque
    else { fatalError("Invalid generated icon") }
    if !opaque {
        guard check.colorAt(x: 0, y: 0)?.alphaComponent == 0 else { fatalError("Rounded icon corner must be transparent") }
    }
    if destination.lastPathComponent == "icon-512-maskable.png" {
        var pinPixels = 0
        for y in 0..<size {
            for x in 0..<size {
                guard let color = check.colorAt(x: x, y: y)?.usingColorSpace(.deviceRGB) else { fatalError("Cannot inspect icon") }
                if min(color.redComponent, color.greenComponent, color.blueComponent) > 0.85 {
                    pinPixels += 1
                    let distance = hypot(Double(x) + 0.5 - Double(size)/2, Double(y) + 0.5 - Double(size)/2)
                    guard distance <= Double(size) * 0.4 else { fatalError("Pin extends outside the maskable safe zone") }
                }
            }
        }
        guard pinPixels > size * size / 10 else { fatalError("Pin is missing from the maskable icon") }
    }
    print("Rendered \(destination.lastPathComponent): \(size)x\(size), alpha=\(!opaque)")
}

try FileManager.default.createDirectory(at: output, withIntermediateDirectories: true)
let icons: [(String, Int, Bool)] = [
    ("icon-master-1024.png", 1024, true),
    ("icon-512.png", 512, false),
    ("icon-512-maskable.png", 512, true),
    ("icon-192.png", 192, false),
    ("apple-touch-icon.png", 180, true),
    ("favicon-32.png", 32, false)
]
for (filename, size, opaque) in icons {
    try render(opaque ? fullBleed : svg, size: size, opaque: opaque, to: output.appendingPathComponent(filename))
}
